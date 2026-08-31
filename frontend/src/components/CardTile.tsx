import { useRef, useState, type PointerEvent } from 'react'
import type { CardSummary, ScheduleState } from '../api/cards'

type CardTileProps = {
  card: CardSummary
  scheduleView?: boolean
  updating?: boolean
  onAddSchedule?: (occurrenceId: number) => void
  onOpen?: (cardId: number) => void
  scheduleUpdating?: boolean
  onScheduleStateChange?: (occurrenceId: number, state: Exclude<ScheduleState, null>) => void
  onScheduleRemove?: (occurrenceId: number) => void
}

const dateFormatter = new Intl.DateTimeFormat('ja-JP', {
  timeZone: 'Asia/Tokyo',
  month: 'long',
  day: 'numeric',
  weekday: 'short',
  hour: '2-digit',
  minute: '2-digit',
})

function formatOccurrence(card: CardSummary) {
  const occurrence = card.occurrences[0]
  if (!occurrence?.starts_at) return card.announced_period ?? '日程未定'
  return dateFormatter.format(new Date(occurrence.starts_at))
}

function CardTile({ card, scheduleView = false, updating = false, onAddSchedule, onOpen, scheduleUpdating = false, onScheduleStateChange, onScheduleRemove }: CardTileProps) {
  const occurrence = card.occurrences[0]
  const primaryOshi = card.oshis[0]
  const state = occurrence?.my_schedule_state
  const canAdd = card.occurrences.length === 1 && state === null && onAddSchedule !== undefined
  const pointerStart = useRef<{ x: number; y: number } | null>(null)
  const suppressOpen = useRef(false)
  const [swipeHint, setSwipeHint] = useState<'commit' | 'remove' | null>(null)

  const pointerDown = (event: PointerEvent<HTMLButtonElement>) => {
    if (!scheduleView || scheduleUpdating) return
    pointerStart.current = { x: event.clientX, y: event.clientY }
    suppressOpen.current = false
    event.currentTarget.setPointerCapture(event.pointerId)
  }
  const pointerMove = (event: PointerEvent<HTMLButtonElement>) => {
    if (!pointerStart.current || !scheduleView) return
    const deltaX = event.clientX - pointerStart.current.x
    const deltaY = event.clientY - pointerStart.current.y
    if (Math.abs(deltaX) < 60 || Math.abs(deltaX) <= Math.abs(deltaY) * 1.5) {
      setSwipeHint(null)
      return
    }
    setSwipeHint(deltaX < 0 ? 'remove' : state === 'checked' ? 'commit' : null)
  }
  const pointerUp = (event: PointerEvent<HTMLButtonElement>) => {
    if (!pointerStart.current || !occurrence) return
    const deltaX = event.clientX - pointerStart.current.x
    const deltaY = event.clientY - pointerStart.current.y
    pointerStart.current = null
    setSwipeHint(null)
    if (Math.abs(deltaX) < 60 || Math.abs(deltaX) <= Math.abs(deltaY) * 1.5) return
    suppressOpen.current = true
    if (deltaX < 0) onScheduleRemove?.(occurrence.id)
    else if (state === 'checked') onScheduleStateChange?.(occurrence.id, 'committed')
  }

  return (
    <article className={`card-tile ${scheduleView ? `card-tile--${state ?? 'none'}` : ''}`} style={{ borderLeftColor: primaryOshi?.color }}>
      {swipeHint && <div className={`swipe-hint swipe-hint--${swipeHint}`}>{swipeHint === 'commit' ? 'コミット' : '予定から外す'}</div>}
      <button className="card-body card-open" type="button" onPointerDown={pointerDown} onPointerMove={pointerMove} onPointerUp={pointerUp} onPointerCancel={() => { pointerStart.current = null; setSwipeHint(null) }} onClick={() => { if (suppressOpen.current) { suppressOpen.current = false; return } onOpen?.(card.id) }}>
        {card.thumbnail.url && <img className="card-thumbnail" src={card.thumbnail.url} alt="" />}
        <div className="card-meta">
          <span>{card.type}</span>
          <span>{primaryOshi?.name}</span>
        </div>
        <h3>{card.title}</h3>
        {occurrence?.label && <p className="occurrence-label">{occurrence.label}</p>}
        <p className="card-date">{formatOccurrence(card)}</p>
        {occurrence?.venue_name && <p className="card-place">{occurrence.venue_name}</p>}
        {card.primary_source && <p className="card-source">{card.primary_source.page_title}<br/><span>{card.primary_source.domain}</span></p>}
      </button>
      {scheduleView ? (
        <div className="schedule-card-footer"><span className="card-state">{state === 'committed' ? 'コミット' : 'チェック'}</span><div className="schedule-card-actions"><button type="button" disabled={scheduleUpdating} onClick={() => occurrence && onScheduleStateChange?.(occurrence.id, state === 'committed' ? 'checked' : 'committed')}>{state === 'committed' ? 'チェックへ戻す' : 'コミットする'}</button><button type="button" disabled={scheduleUpdating} onClick={() => occurrence && onScheduleRemove?.(occurrence.id)}>予定から外す</button></div></div>
      ) : (
        <button className="card-action" type="button" disabled={!canAdd || updating} onClick={() => occurrence && onAddSchedule?.(occurrence.id)}>
          {updating ? '追加しています…' : state === 'committed' ? 'コミット済み' : state === 'checked' ? 'チェック済み' : card.occurrences.length > 1 ? '公演を選択' : '⇨マイスケジュール'}
        </button>
      )}
    </article>
  )
}

export default CardTile
