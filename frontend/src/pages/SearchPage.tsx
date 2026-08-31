import { useEffect, useState } from 'react'
import { fetchOshis, updateFollowing, type OshiSummary, type OshiType } from '../api/oshis'
import BottomNav, { type MainPage } from '../components/BottomNav'
import { OshiIcon, SearchIcon, UserIcon } from '../components/Icons'
import NotificationButton from '../components/NotificationButton'

type LoadState = 'loading' | 'ready' | 'error'

const typeChoices: { value: OshiType; label: string }[] = [
  { value: 'person', label: '人物' },
  { value: 'group', label: 'グループ' },
  { value: 'work', label: '作品' },
  { value: 'character', label: 'キャラクター' },
  { value: 'other', label: 'その他' },
]

type Props = { onNavigate: (page: MainPage) => void; onAccount: () => void; onNotifications: () => void; onOpenOshi: (oshiId: number) => void; onCreateOshi: () => void; unreadCount: number }

function SearchPage({ onNavigate, onAccount, onNotifications, onOpenOshi, onCreateOshi, unreadCount }: Props) {
  const [query, setQuery] = useState('')
  const [selectedType, setSelectedType] = useState<OshiType | ''>('')
  const [oshis, setOshis] = useState<OshiSummary[]>([])
  const [loadState, setLoadState] = useState<LoadState>('loading')
  const [errorMessage, setErrorMessage] = useState('')
  const [updatingOshiId, setUpdatingOshiId] = useState<number | null>(null)

  useEffect(() => {
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      setLoadState('loading')
      setErrorMessage('')
      fetchOshis(query, selectedType, controller.signal)
        .then((data) => {
          setOshis(data)
          setLoadState('ready')
        })
        .catch((error: unknown) => {
          if (error instanceof DOMException && error.name === 'AbortError') return
          setErrorMessage(error instanceof Error ? error.message : '推し情報を取得できませんでした。')
          setLoadState('error')
        })
    }, 250)

    return () => {
      window.clearTimeout(timer)
      controller.abort()
    }
  }, [query, selectedType])

  const hasFilter = query.trim() !== '' || selectedType !== ''

  const toggleFollowing = async (oshi: OshiSummary) => {
    setUpdatingOshiId(oshi.id)
    try {
      await updateFollowing(oshi.id, !oshi.is_following)
      setOshis((current) => current.map((item) => item.id === oshi.id ? { ...item, is_following: !item.is_following } : item))
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : '推し登録を変更できませんでした。')
      setLoadState('error')
    } finally {
      setUpdatingOshiId(null)
    }
  }

  return (
    <div className="app-frame">
      <header className="top-row">
        <h1>推し検索</h1>
        <div className="top-actions">
          <NotificationButton unreadCount={unreadCount} onClick={onNotifications} />
          <button className="round-action" type="button" aria-label="アカウント設定" onClick={onAccount}><UserIcon /></button>
        </div>
      </header>

      <div className="search-area">
        <label className="search-field">
          <span className="visually-hidden">推しを検索</span>
          <SearchIcon />
          <input type="search" value={query} maxLength={100} placeholder="名前・読み方・別名で検索" onChange={(event) => setQuery(event.target.value)} />
          {query && <button className="clear-search" type="button" aria-label="検索文字を消す" onClick={() => setQuery('')}>×</button>}
        </label>
      </div>

      <main className="search-content">
        <h2>種類から探す</h2>
        <div className="type-strip" aria-label="推しの種類">
          {typeChoices.map((choice) => {
            const selected = selectedType === choice.value
            return <button key={choice.value} type="button" aria-pressed={selected} onClick={() => setSelectedType(selected ? '' : choice.value)}>{choice.label}</button>
          })}
        </div>

        <h2>{hasFilter ? '検索結果' : '最近人気の推し'}</h2>
        <div className="result-region" aria-live="polite">
          {loadState === 'loading' && <p className="state-message">検索しています…</p>}
          {loadState === 'error' && <p className="state-message state-message--error">{errorMessage}</p>}
          {loadState === 'ready' && oshis.length === 0 && <p className="state-message">該当する推しが見つかりません</p>}
          {loadState === 'ready' && oshis.length > 0 && (
            <div className="oshi-list">
              {oshis.map((oshi) => (
                <article className="oshi-row" key={oshi.id}>
                  <div className="oshi-icon" style={{ color: oshi.color }}><OshiIcon name={oshi.icon_key} /></div>
                  <div className="oshi-copy">
                    <button className="oshi-profile-link" type="button" onClick={() => onOpenOshi(oshi.id)}><h3>{oshi.name}</h3><span>プロフィールを見る</span></button>
                    <p>{oshi.categories.join('・')}</p>
                    {oshi.name_kana && <p className="oshi-kana">{oshi.name_kana}</p>}
                  </div>
                  <button className={`follow-action ${oshi.is_following ? 'follow-action--active' : ''}`} type="button" disabled={updatingOshiId === oshi.id} onClick={() => toggleFollowing(oshi)}>{updatingOshiId === oshi.id ? '更新中…' : oshi.is_following ? '登録済み' : '推しに登録'}</button>
                </article>
              ))}
            </div>
          )}
        </div>
        <button className="secondary-button create-oshi-button" type="button" onClick={onCreateOshi}>見つからない場合：推しを新規登録</button>
      </main>

      <BottomNav current="search" onNavigate={onNavigate} />
    </div>
  )
}

export default SearchPage
