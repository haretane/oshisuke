import { useState, type FormEvent } from 'react'
import { createOshi, type OshiType } from '../api/oshis'
import { OshiIcon } from '../components/Icons'

type Props = { onClose: () => void; onCreated: (oshiId: number) => void }
const types: { value: OshiType; label: string }[] = [{ value: 'person', label: '人物' }, { value: 'group', label: 'グループ' }, { value: 'work', label: '作品' }, { value: 'character', label: 'キャラクター' }, { value: 'other', label: 'その他' }]
const icons = [{ value: 'microphone', label: 'マイク' }, { value: 'music', label: '音楽' }, { value: 'play', label: '映像' }]

function OshiCreatePage({ onClose, onCreated }: Props) {
  const [name, setName] = useState('')
  const [shortName, setShortName] = useState('')
  const [nameKana, setNameKana] = useState('')
  const [type, setType] = useState<OshiType>('person')
  const [description, setDescription] = useState('')
  const [iconKey, setIconKey] = useState('microphone')
  const [color, setColor] = useState('#C74D67')
  const [message, setMessage] = useState('')
  const [saving, setSaving] = useState(false)

  const submit = async (event: FormEvent) => {
    event.preventDefault(); setSaving(true); setMessage('')
    try {
      const response = await createOshi({ name, short_name: shortName, name_kana: nameKana, type, description, icon_key: iconKey, color })
      onCreated(response.data.id)
    } catch (error) {
      setMessage(error instanceof Error ? error.message : '推しプロフィールを作成できませんでした。')
    } finally { setSaving(false) }
  }

  return <div className="app-frame"><header className="top-row"><h1>推しを新規登録</h1><button className="round-action" type="button" aria-label="閉じる" onClick={onClose}>×</button></header><main className="card-form-content">
    <form className="card-form" onSubmit={submit}>
      <p className="field-help">同じ推しがいないか、先に検索結果をご確認ください。</p>
      <label>正式名称（必須）<input value={name} maxLength={150} required onChange={(event) => setName(event.target.value)} /></label>
      <label>略称（任意・20文字まで）<input value={shortName} maxLength={20} placeholder="ホームの狭いタブで使います" onChange={(event) => setShortName(event.target.value)} /></label>
      <label>読み方（任意）<input value={nameKana} maxLength={150} placeholder="ひらがな" onChange={(event) => setNameKana(event.target.value)} /></label>
      <label>種類<select value={type} onChange={(event) => setType(event.target.value as OshiType)}>{types.map((item) => <option value={item.value} key={item.value}>{item.label}</option>)}</select></label>
      <label>短い紹介（任意）<textarea rows={4} maxLength={2000} value={description} onChange={(event) => setDescription(event.target.value)} /></label>
      <fieldset><legend>アイコン</legend><div className="oshi-icon-choices">{icons.map((icon) => <button type="button" aria-pressed={iconKey === icon.value} onClick={() => setIconKey(icon.value)} key={icon.value}><OshiIcon name={icon.value} /><span>{icon.label}</span></button>)}</div></fieldset>
      <label>色<input className="oshi-color-input" type="color" value={color} onChange={(event) => setColor(event.target.value.toUpperCase())} /></label>
      {message && <p className="auth-error" role="alert">{message}</p>}
      <button className="primary-button" type="submit" disabled={saving}>{saving ? '登録しています…' : '推しを登録'}</button>
    </form>
  </main></div>
}

export default OshiCreatePage
