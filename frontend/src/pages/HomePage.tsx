import { useEffect, useState } from 'react'
import { addCheckedSchedule, fetchHomeCards, fetchMyOshis, type CardSort, type CardSummary, type MyOshi } from '../api/cards'
import BottomNav, { type MainPage } from '../components/BottomNav'
import CardTile from '../components/CardTile'
import { CalendarIcon, CardsIcon, UserIcon } from '../components/Icons'
import NotificationButton from '../components/NotificationButton'
import ScheduleCalendar from '../components/ScheduleCalendar'

type Props = { onNavigate: (page: MainPage) => void; onAccount: () => void; onCreate: () => void; onOpenCard: (cardId: number) => void; onNotifications: () => void; unreadCount: number }

function HomePage({ onNavigate, onAccount, onCreate, onOpenCard, onNotifications, unreadCount }: Props) {
  const [sort, setSort] = useState<CardSort>('new')
  const [cards, setCards] = useState<CardSummary[]>([])
  const [message, setMessage] = useState('読み込んでいます…')
  const [updatingOccurrenceId, setUpdatingOccurrenceId] = useState<number | null>(null)
  const [view, setView] = useState<'cards' | 'calendar'>('cards')
  const [oshis, setOshis] = useState<MyOshi[]>([])
  const [selectedOshiId, setSelectedOshiId] = useState<number | null>(null)

  useEffect(() => {
    const controller = new AbortController()
    fetchMyOshis(controller.signal).then(setOshis).catch(() => undefined)
    return () => controller.abort()
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    fetchHomeCards(sort, controller.signal, selectedOshiId ?? undefined)
      .then((data) => {
        setCards(data)
        setMessage(data.length === 0 ? '表示できるカードがありません' : '')
      })
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') return
        setMessage(error instanceof Error ? error.message : 'カードを取得できませんでした。')
      })
    return () => controller.abort()
  }, [sort, selectedOshiId])

  const changeSort = (nextSort: CardSort) => {
    setMessage('読み込んでいます…')
    setSort(nextSort)
  }

  const addSchedule = async (occurrenceId: number) => {
    setUpdatingOccurrenceId(occurrenceId)
    try {
      await addCheckedSchedule(occurrenceId)
      setCards((current) => current.map((card) => ({
        ...card,
        occurrences: card.occurrences.map((occurrence) => occurrence.id === occurrenceId ? { ...occurrence, my_schedule_state: 'checked' } : occurrence),
      })))
    } catch (error) {
      setMessage(error instanceof Error ? error.message : '予定を追加できませんでした。')
    } finally {
      setUpdatingOccurrenceId(null)
    }
  }

  return (
    <div className="app-frame">
      <header className="top-row"><div className="product-name">推しスケ</div><div className="top-actions"><NotificationButton unreadCount={unreadCount} onClick={onNotifications} /><button className="round-action" type="button" aria-label="アカウント設定" onClick={onAccount}><UserIcon /></button></div></header>
      <div className="oshi-strip" aria-label="表示する推し"><button type="button" aria-pressed={selectedOshiId === null} onClick={() => setSelectedOshiId(null)}>すべて</button>{oshis.map((oshi) => <button type="button" key={oshi.id} aria-pressed={selectedOshiId === oshi.id} title={oshi.name} onClick={() => setSelectedOshiId(oshi.id)}>{oshi.short_name || oshi.name}</button>)}</div>
      <div className="sort-action-row">
        <div className="sort-tabs" role="tablist" aria-label="カードの並び順">
          {([['new', '新着'], ['date', '日程'], ['popular', '人気']] as const).map(([value, label]) => <button key={value} type="button" role="tab" aria-selected={sort === value} disabled={view === 'calendar'} onClick={() => changeSort(value)}>{label}</button>)}
        </div>
        <button className="new-card-button" type="button" onClick={onCreate}>＋新規カード</button>
        <button className="view-toggle home-view-toggle" type="button" aria-label={view === 'cards' ? 'カレンダー表示' : 'カード表示'} aria-pressed={view === 'calendar'} title={view === 'cards' ? 'カレンダー表示' : 'カード表示'} onClick={() => setView((current) => current === 'cards' ? 'calendar' : 'cards')}>{view === 'cards' ? <CalendarIcon /> : <CardsIcon />}</button>
      </div>
      {view === 'cards' ? <main className="card-feed">{message && <p className="state-message">{message}</p>}{cards.map((card) => <CardTile key={card.id} card={card} onOpen={onOpenCard} onAddSchedule={addSchedule} updating={card.occurrences.some((occurrence) => occurrence.id === updatingOccurrenceId)} />)}</main> : <main className="calendar-content">{message && <p className="state-message">{message}</p>}{!message && <ScheduleCalendar cards={cards} onOpenCard={onOpenCard} calendarLabel="推しスケカレンダー" />}</main>}
      <BottomNav current="home" onNavigate={onNavigate} />
    </div>
  )
}

export default HomePage
