import { useEffect, useState } from 'react'
import { fetchOshiProfile, updateFollowing, updateMyOshiAppearance, type OshiProfile } from '../api/oshis'
import { OshiIcon } from '../components/Icons'

type Props = { oshiId: number; onBack: () => void; onCreateCard: () => void }

function OshiProfilePage({ oshiId, onBack, onCreateCard }: Props) {
  const [profile, setProfile] = useState<OshiProfile | null>(null)
  const [message, setMessage] = useState('読み込んでいます…')
  const [updating, setUpdating] = useState(false)
  const [appearanceOpen, setAppearanceOpen] = useState(false)
  const [appearanceIcon, setAppearanceIcon] = useState('microphone')
  const [appearanceColor, setAppearanceColor] = useState('#C74D67')
  const [appearanceSaving, setAppearanceSaving] = useState(false)

  useEffect(() => {
    const controller = new AbortController()
    fetchOshiProfile(oshiId, controller.signal)
      .then((data) => { setProfile(data); setMessage('') })
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') return
        setMessage(error instanceof Error ? error.message : '推しプロフィールを取得できませんでした。')
      })
    return () => controller.abort()
  }, [oshiId])

  const toggleFollowing = async () => {
    if (!profile) return
    setUpdating(true)
    try {
      await updateFollowing(profile.id, !profile.is_following)
      setProfile({ ...profile, is_following: !profile.is_following, follower_count: profile.follower_count + (profile.is_following ? -1 : 1) })
    } catch (error) {
      setMessage(error instanceof Error ? error.message : '推し登録を変更できませんでした。')
    } finally {
      setUpdating(false)
    }
  }

  const openAppearance = () => {
    if (!profile) return
    setAppearanceIcon(profile.icon_key)
    setAppearanceColor(profile.color)
    setAppearanceOpen(true)
  }

  const saveAppearance = async (reset = false) => {
    if (!profile) return
    setAppearanceSaving(true); setMessage('')
    try {
      const result = await updateMyOshiAppearance(profile.id, reset ? { reset: true } : { icon_key: appearanceIcon, color: appearanceColor })
      setProfile({ ...profile, icon_key: result.icon_key, color: result.color, has_custom_appearance: result.has_custom_appearance })
      setAppearanceOpen(false)
      setMessage(reset ? '共有の初期設定に戻しました。' : '自分用のアイコンと色を変更しました。')
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'マイカスタムを変更できませんでした。')
    } finally { setAppearanceSaving(false) }
  }

  return <div className="app-frame">
    <header className="top-row card-detail-header"><button className="round-action" type="button" aria-label="前の画面へ戻る" onClick={onBack}>←</button><h1>推しプロフィール</h1><span className="header-spacer" /></header>
    <main className="oshi-profile-content">
      {message && <p className="state-message">{message}</p>}
      {profile && <>
        <section className="oshi-profile-hero">
          <div className="oshi-profile-icon" style={{ color: profile.color }}><OshiIcon name={profile.icon_key} /></div>
          <div><h2>{profile.name}</h2>{profile.short_name && profile.short_name !== profile.name && <p className="oshi-profile-short">略称：{profile.short_name}</p>}<p>{profile.type_label} ・ 推し登録 {profile.follower_count}人</p></div>
          <button className={`follow-action ${profile.is_following ? 'follow-action--active' : ''}`} type="button" disabled={updating} onClick={toggleFollowing}>{updating ? '更新中…' : profile.is_following ? '登録済み' : '推しに登録'}</button>
        </section>
        {profile.name_kana && <p className="oshi-profile-kana">{profile.name_kana}</p>}
        {profile.description && <p className="oshi-profile-description">{profile.description}</p>}
        {profile.is_following && <section className="oshi-profile-section appearance-section"><h2>マイカスタム</h2>{appearanceOpen ? <div className="appearance-editor">
          <div className="oshi-icon-choices">{[{ value: 'microphone', label: 'マイク' }, { value: 'music', label: '音楽' }, { value: 'play', label: '映像' }].map((icon) => <button type="button" aria-pressed={appearanceIcon === icon.value} onClick={() => setAppearanceIcon(icon.value)} key={icon.value}><OshiIcon name={icon.value} /><span>{icon.label}</span></button>)}</div>
          <label>色<input type="color" value={appearanceColor} onChange={(event) => setAppearanceColor(event.target.value.toUpperCase())} /></label>
          <div className="card-edit-actions"><button className="secondary-button" type="button" disabled={appearanceSaving} onClick={() => setAppearanceOpen(false)}>キャンセル</button><button className="primary-button" type="button" disabled={appearanceSaving} onClick={() => saveAppearance(false)}>変更する</button></div>
          {profile.has_custom_appearance && <button className="appearance-reset" type="button" disabled={appearanceSaving} onClick={() => saveAppearance(true)}>共有の初期設定に戻す</button>}
        </div> : <><p className="muted-detail">アイコンと色を、自分の画面だけ好きな組み合わせに変更できます。</p><button className="edit-card-button" type="button" onClick={openAppearance}>マイカスタムを変更</button></>}</section>}
        {profile.alternate_names.length > 0 && <section className="oshi-profile-section"><h2>別名・英語表記</h2><p>{profile.alternate_names.join('・')}</p></section>}
        <section className="oshi-profile-section"><h2>公式リンク</h2>{profile.links.length === 0 ? <p className="muted-detail">公式リンクはまだ登録されていません。</p> : <ul className="source-list">{profile.links.map((link) => <li key={link.id}><a href={link.url} target="_blank" rel="noreferrer">{link.label} <span>↗</span></a></li>)}</ul>}</section>
        {profile.is_following && <button className="primary-button oshi-profile-create" type="button" onClick={onCreateCard}>＋ カードを追加</button>}
      </>}
    </main>
  </div>
}

export default OshiProfilePage
