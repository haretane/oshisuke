import { useEffect, useRef, useState } from 'react'
import { fetchScheduleCards, removeSchedule, setScheduleState, type CardSummary, type ScheduleState } from '../api/cards'
import BottomNav, { type MainPage } from '../components/BottomNav'
import CardTile from '../components/CardTile'
import { CalendarIcon, CardsIcon, UserIcon } from '../components/Icons'
import NotificationButton from '../components/NotificationButton'
import ScheduleCalendar from '../components/ScheduleCalendar'

type Props = { onNavigate: (page: MainPage) => void; onAccount: () => void; onOpenCard: (cardId: number) => void; onNotifications: () => void; unreadCount: number }
type ActiveState = Exclude<ScheduleState, null>
type UndoAction = { card: CardSummary; previousState: ActiveState }

function MySchedulePage({ onNavigate, onAccount, onOpenCard, onNotifications, unreadCount }: Props) {
  const [state, setState] = useState<ActiveState>('checked')
  const [cards, setCards] = useState<CardSummary[]>([])
  const [message, setMessage] = useState('読み込んでいます…')
  const [updatingId, setUpdatingId] = useState<number | null>(null)
  const [undoAction, setUndoAction] = useState<UndoAction | null>(null)
  const undoTimer = useRef<number | null>(null)
  const [view, setView] = useState<'cards' | 'calendar'>('cards')
  const [calendarCards, setCalendarCards] = useState<CardSummary[]>([])

  useEffect(() => {
    const controller = new AbortController()
    const request = view === 'calendar'
      ? Promise.all([fetchScheduleCards('committed', controller.signal), fetchScheduleCards('checked', controller.signal)])
          .then(([committed, checked]) => { setCalendarCards([...committed, ...checked]); return [...committed, ...checked] })
      : fetchScheduleCards(state, controller.signal).then((data) => { setCards(data); return data })
    request
      .then((data) => {
        setMessage(data.length === 0 ? '予定はまだありません' : '')
      })
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') return
        setMessage(error instanceof Error ? error.message : '予定を取得できませんでした。')
      })
    return () => controller.abort()
  }, [state, view])

  useEffect(() => () => { if (undoTimer.current !== null) window.clearTimeout(undoTimer.current) }, [])

  const changeState = (nextState: ActiveState) => {
    setMessage('読み込んでいます…')
    setState(nextState)
  }

  const showUndo = (card: CardSummary) => {
    if (undoTimer.current !== null) window.clearTimeout(undoTimer.current)
    setUndoAction({ card, previousState: state })
    undoTimer.current = window.setTimeout(() => setUndoAction(null), 5000)
  }

  const changeSchedule = async (card: CardSummary, nextState: ActiveState) => {
    const occurrence = card.occurrences[0]
    if (!occurrence) return
    setUpdatingId(occurrence.id)
    try {
      await setScheduleState(occurrence.id, nextState)
      setCards((current) => current.filter((item) => item !== card))
      showUndo(card)
    } catch (error) {
      setMessage(error instanceof Error ? error.message : '予定を変更できませんでした。')
    } finally {
      setUpdatingId(null)
    }
  }

  const remove = async (card: CardSummary) => {
    const occurrence = card.occurrences[0]
    if (!occurrence) return
    setUpdatingId(occurrence.id)
    try {
      await removeSchedule(occurrence.id)
      setCards((current) => current.filter((item) => item !== card))
      showUndo(card)
    } catch (error) {
      setMessage(error instanceof Error ? error.message : '予定から外せませんでした。')
    } finally {
      setUpdatingId(null)
    }
  }

  const undo = async () => {
    if (!undoAction) return
    const occurrence = undoAction.card.occurrences[0]
    if (!occurrence) return
    setUpdatingId(occurrence.id)
    try {
      await setScheduleState(occurrence.id, undoAction.previousState)
      setCards((current) => [undoAction.card, ...current])
      setUndoAction(null)
      if (undoTimer.current !== null) window.clearTimeout(undoTimer.current)
    } catch (error) {
      setMessage(error instanceof Error ? error.message : '元に戻せませんでした。')
    } finally {
      setUpdatingId(null)
    }
  }

  return (
    <div className="app-frame">
      <header className="top-row"><h1>マイスケジュール</h1><div className="top-actions"><NotificationButton unreadCount={unreadCount} onClick={onNotifications} /><button className="round-action" type="button" aria-label="アカウント設定" onClick={onAccount}><UserIcon /></button></div></header>
      <div className="schedule-tools"><div className="schedule-tabs" role="tablist" aria-label="予定の状態"><button type="button" role="tab" aria-selected={state === 'checked'} disabled={view === 'calendar'} onClick={() => changeState('checked')}>チェック</button><button type="button" role="tab" aria-selected={state === 'committed'} disabled={view === 'calendar'} onClick={() => changeState('committed')}>コミット</button></div><button className="view-toggle" type="button" aria-label={view === 'cards' ? 'カレンダー表示' : 'カード表示'} aria-pressed={view === 'calendar'} title={view === 'cards' ? 'カレンダー表示' : 'カード表示'} onClick={() => { setMessage('読み込んでいます…'); setView((current) => current === 'cards' ? 'calendar' : 'cards') }}>{view === 'cards' ? <CalendarIcon /> : <CardsIcon />}</button></div>
      {view === 'cards' ? <main className="card-feed">{message && <p className="state-message">{message}</p>}{cards.map((card) => <CardTile key={`${card.id}-${card.occurrences[0]?.id}`} card={card} scheduleView onOpen={onOpenCard} scheduleUpdating={card.occurrences[0]?.id === updatingId} onScheduleStateChange={(_, nextState) => changeSchedule(card, nextState)} onScheduleRemove={() => remove(card)} />)}</main> : <main className="calendar-content">{message && <p className="state-message">{message}</p>}{!message && <ScheduleCalendar cards={calendarCards} onOpenCard={onOpenCard} />}</main>}
      {undoAction && <div className="undo-toast" role="status"><span>予定を変更しました</span><button type="button" onClick={undo}>元に戻す</button></div>}
      <BottomNav current="schedule" onNavigate={onNavigate} />
    </div>
  )
}

export default MySchedulePage
