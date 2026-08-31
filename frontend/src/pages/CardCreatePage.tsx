import { useEffect, useState, type FormEvent } from 'react'
import { createCard, fetchMyOshis, type CardType, type MyOshi } from '../api/cards'
import { createUrlPreview, type UrlPreview } from '../api/previews'

type Props = { onClose: () => void; onCreated: () => void; initialOshiId?: number }

const cardTypes: { value: CardType; label: string }[] = [
  { value: 'concert', label: 'ライブ・コンサート' },
  { value: 'tour', label: 'ツアー' },
  { value: 'event', label: 'イベント' },
  { value: 'ticket', label: 'チケット' },
  { value: 'goods', label: 'グッズ発売' },
  { value: 'release', label: '作品発売' },
  { value: 'streaming', label: '配信' },
  { value: 'media', label: 'メディア出演' },
  { value: 'movie', label: '映画公開' },
  { value: 'other', label: 'その他' },
]

const venueTypes = new Set<CardType>(['concert', 'tour', 'event', 'other'])
const multipleOccurrenceTypes = new Set<CardType>(['concert', 'tour', 'event'])
type OccurrenceInput = { label: string; startsAt: string; venueName: string }
const emptyOccurrence = (): OccurrenceInput => ({ label: '', startsAt: '', venueName: '' })

function CardCreatePage({ onClose, onCreated, initialOshiId }: Props) {
  const [oshis, setOshis] = useState<MyOshi[]>([])
  const [type, setType] = useState<CardType>('event')
  const [oshiId, setOshiId] = useState('')
  const [title, setTitle] = useState('')
  const [occurrences, setOccurrences] = useState<OccurrenceInput[]>([emptyOccurrence()])
  const [summary, setSummary] = useState('')
  const [sourceUrl, setSourceUrl] = useState('')
  const [message, setMessage] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [preview, setPreview] = useState<UrlPreview | null>(null)
  const [previewing, setPreviewing] = useState(false)
  const [useThumbnail, setUseThumbnail] = useState(true)

  useEffect(() => {
    const controller = new AbortController()
    fetchMyOshis(controller.signal)
      .then((data) => {
        setOshis(data)
        const initial = data.find((oshi) => oshi.id === initialOshiId) ?? data[0]
        if (initial) setOshiId(String(initial.id))
      })
      .catch((error: unknown) => setMessage(error instanceof Error ? error.message : '推しを取得できませんでした。'))
    return () => controller.abort()
  }, [initialOshiId])

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSubmitting(true)
    setMessage('')
    try {
      await createCard({
        type,
        oshi_id: Number(oshiId),
        title,
        summary,
        occurrences: occurrences.map((occurrence) => ({
          label: occurrence.label,
          starts_at: occurrence.startsAt ? new Date(occurrence.startsAt).toISOString() : null,
          venue_name: venueTypes.has(type) ? occurrence.venueName : '',
        })),
        source_url: sourceUrl,
        preview_token: preview?.token ?? '',
        use_thumbnail: useThumbnail,
      })
      onCreated()
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'カードを追加できませんでした。')
    } finally {
      setSubmitting(false)
    }
  }

  const updateOccurrence = (index: number, field: keyof OccurrenceInput, value: string) => {
    setOccurrences((current) => current.map((occurrence, occurrenceIndex) => occurrenceIndex === index ? { ...occurrence, [field]: value } : occurrence))
  }

  const loadPreview = async () => {
    if (!sourceUrl) return
    setPreviewing(true)
    setMessage('')
    try {
      const data = await createUrlPreview(sourceUrl)
      setPreview(data)
      setUseThumbnail(data.thumbnail_url !== null)
    } catch (error) {
      setPreview(null)
      setMessage(error instanceof Error ? error.message : 'ページ情報を取得できませんでした。URLを入力したままカード作成は続けられます。')
    } finally {
      setPreviewing(false)
    }
  }

  return (
    <div className="app-frame">
      <header className="top-row"><h1>カードを追加</h1><button className="round-action" type="button" aria-label="閉じる" onClick={onClose}>×</button></header>
      <main className="card-form-content">
        {oshis.length === 0 && !message ? <p className="state-message">先に「推し検索」から推しを登録してください。</p> : (
          <form className="card-form" onSubmit={submit}>
            <fieldset><legend>カード種類</legend><div className="card-type-grid">{cardTypes.map((choice) => <button key={choice.value} type="button" aria-pressed={type === choice.value} onClick={() => setType(choice.value)}>{choice.label}</button>)}</div></fieldset>
            <label>対象の推し<select value={oshiId} required onChange={(event) => setOshiId(event.target.value)}>{oshis.map((oshi) => <option key={oshi.id} value={oshi.id}>{oshi.name}</option>)}</select></label>
            <label>タイトル（必須）<input value={title} maxLength={200} required onChange={(event) => setTitle(event.target.value)} /></label>
            <fieldset className="occurrence-fields"><legend>{multipleOccurrenceTypes.has(type) ? '日程・公演' : '日程'}</legend>
              {occurrences.map((occurrence, index) => <div className="occurrence-form-item" key={index}>
                {multipleOccurrenceTypes.has(type) && <label>公演名（任意）<input value={occurrence.label} maxLength={150} placeholder="例：東京公演、第1部" onChange={(event) => updateOccurrence(index, 'label', event.target.value)} /></label>}
                <label>日時（未定の場合は空欄）<input type="datetime-local" value={occurrence.startsAt} onChange={(event) => updateOccurrence(index, 'startsAt', event.target.value)} /></label>
                {venueTypes.has(type) && <label>会場（任意）<input value={occurrence.venueName} maxLength={200} onChange={(event) => updateOccurrence(index, 'venueName', event.target.value)} /></label>}
                {occurrences.length > 1 && <button className="remove-occurrence" type="button" onClick={() => setOccurrences((current) => current.filter((_, occurrenceIndex) => occurrenceIndex !== index))}>この日程を削除</button>}
              </div>)}
              {multipleOccurrenceTypes.has(type) && <button className="secondary-button" type="button" onClick={() => setOccurrences((current) => [...current, emptyOccurrence()])}>＋ 別の日程を追加</button>}
            </fieldset>
            <label>内容（任意）<textarea value={summary} rows={5} onChange={(event) => setSummary(event.target.value)} /></label>
            <label>情報元URL（任意）<input type="url" value={sourceUrl} placeholder="https://" maxLength={2048} onChange={(event) => { setSourceUrl(event.target.value); setPreview(null) }} /></label>
            {sourceUrl && <button className="secondary-button" type="button" disabled={previewing} onClick={loadPreview}>{previewing ? 'ページ情報を確認しています…' : 'ページ情報を確認'}</button>}
            {preview && <div className="url-preview">{preview.thumbnail_url && <img src={preview.thumbnail_url} alt="取得したサムネイル候補" />}<div><strong>{preview.page_title ?? 'ページ名称を取得できませんでした'}</strong><span>{preview.domain}</span></div>{preview.thumbnail_url && <label><input type="checkbox" checked={useThumbnail} onChange={(event) => setUseThumbnail(event.target.checked)} />この画像を使う</label>}</div>}
            <p className="field-help">取得できない場合も、入力したURLのままカードを追加できます。</p>
            {message && <p className="auth-error" role="alert">{message}</p>}
            <button className="primary-button" type="submit" disabled={submitting || !oshiId}>{submitting ? '追加しています…' : 'カードを追加'}</button>
          </form>
        )}
      </main>
    </div>
  )
}

export default CardCreatePage
