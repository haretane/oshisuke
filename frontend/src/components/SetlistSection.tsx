import { useEffect, useState } from 'react'
import { fetchSetlist, saveSetlist, type Setlist, type SetlistItemType } from '../api/setlists'
import type { Occurrence } from '../api/cards'

type Props = { occurrences: Occurrence[] }
type DraftItem = { type: SetlistItemType; title: string }

const itemLabels: Record<SetlistItemType, string> = {
  song: '曲', mc: 'MC', op: 'OP', se: 'SE', ed: 'ED', other: 'その他',
}

function SetlistSection({ occurrences }: Props) {
  const [occurrenceId, setOccurrenceId] = useState(occurrences[0]?.id ?? 0)
  const [setlist, setSetlist] = useState<Setlist | null>(null)
  const [canCreate, setCanCreate] = useState(false)
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState(false)
  const [songCount, setSongCount] = useState(10)
  const [items, setItems] = useState<DraftItem[]>([])
  const [message, setMessage] = useState('')

  useEffect(() => {
    if (!occurrenceId) return
    const controller = new AbortController()
    fetchSetlist(occurrenceId, controller.signal)
      .then((data) => { setSetlist(data.setlist); setCanCreate(data.can_create); setEditing(false) })
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') return
        setMessage(error instanceof Error ? error.message : 'セットリストを取得できませんでした。')
      })
      .finally(() => setLoading(false))
    return () => controller.abort()
  }, [occurrenceId])

  const startNew = () => {
    const count = Math.max(1, Math.min(100, songCount))
    setItems(Array.from({ length: count }, () => ({ type: 'song' as const, title: '' })))
    setEditing(true)
  }

  const startEdit = () => {
    if (!setlist) return
    setItems(setlist.items.map((item) => ({ type: item.type, title: item.title })))
    setEditing(true)
  }

  const save = async () => {
    const cleaned = items.map((item) => ({ ...item, title: item.title.trim() })).filter((item) => item.title !== '')
    if (cleaned.length === 0) { setMessage('曲名または内容を1行以上入力してください。'); return }
    setLoading(true)
    setMessage('')
    try {
      const response = await saveSetlist(occurrenceId, cleaned)
      setSetlist(response.data.setlist)
      setCanCreate(false)
      setEditing(false)
      setMessage('セットリストを保存しました。')
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'セットリストを保存できませんでした。')
    } finally {
      setLoading(false)
    }
  }

  let songNumber = 0
  return <section className="detail-section setlist-section">
    <h2>セットリスト</h2>
    {occurrences.length > 1 && <label className="setlist-occurrence">公演
      <select value={occurrenceId} onChange={(event) => { setLoading(true); setMessage(''); setEditing(false); setOccurrenceId(Number(event.target.value)) }}>
        {occurrences.map((occurrence, index) => <option value={occurrence.id} key={occurrence.id}>{occurrence.label || `日程${index + 1}`}</option>)}
      </select>
    </label>}
    {message && <p className="edit-requirement">{message}</p>}
    {loading && !editing ? <p className="muted-detail">読み込んでいます…</p> : editing ? <div className="setlist-editor">
      {items.map((item, index) => <div className="setlist-edit-row" key={index}>
        <select aria-label={`${index + 1}行目の種類`} value={item.type} onChange={(event) => setItems((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, type: event.target.value as SetlistItemType } : row))}>
          {Object.entries(itemLabels).map(([value, label]) => <option value={value} key={value}>{label}</option>)}
        </select>
        <input aria-label={`${index + 1}行目の内容`} maxLength={300} value={item.title} placeholder={item.type === 'song' ? '曲名' : '内容'} onChange={(event) => setItems((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, title: event.target.value } : row))} />
        <button type="button" aria-label={`${index + 1}行目を削除`} onClick={() => setItems((current) => current.filter((_, rowIndex) => rowIndex !== index))}>×</button>
      </div>)}
      <button className="secondary-button" type="button" onClick={() => setItems((current) => [...current, { type: 'song', title: '' }])}>＋ 行を追加</button>
      <div className="card-edit-actions"><button className="secondary-button" type="button" disabled={loading} onClick={() => setEditing(false)}>キャンセル</button><button className="primary-button" type="button" disabled={loading} onClick={save}>{loading ? '保存しています…' : '保存する'}</button></div>
    </div> : setlist ? <>
      <ol className="setlist-items">{setlist.items.map((item) => {
        const number = item.type === 'song' ? ++songNumber : null
        return <li className={item.type === 'song' ? '' : 'setlist-note'} key={item.id}><span>{number ?? itemLabels[item.type]}</span><strong>{item.title}</strong></li>
      })}</ol>
      {setlist.can_edit && <button className="edit-card-button" type="button" onClick={startEdit}>セットリストを編集</button>}
    </> : canCreate ? <div className="setlist-empty">
      <p className="muted-detail">セットリストはまだ登録されていません。</p>
      <label>曲数<input type="number" min="1" max="100" value={songCount} onChange={(event) => setSongCount(Number(event.target.value))} /></label>
      <button className="primary-button" type="button" onClick={startNew}>セットリストを登録</button>
    </div> : <p className="muted-detail">セットリストはまだ登録されていません。</p>}
  </section>
}

export default SetlistSection
