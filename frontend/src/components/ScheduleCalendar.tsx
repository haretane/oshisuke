import { useMemo, useState } from 'react'
import type { CardSummary } from '../api/cards'

type Props = { cards: CardSummary[]; onOpenCard: (cardId: number) => void; calendarLabel?: string }
type CalendarEntry = { key: string; occurrenceId: number; card: CardSummary; state: 'checked' | 'committed' | 'none' }

const weekdays = ['月', '火', '水', '木', '金', '土', '日']
const dateParts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Tokyo', year: 'numeric', month: '2-digit', day: '2-digit' })

function dateKey(value: string) {
  const parts = dateParts.formatToParts(new Date(value))
  const get = (type: string) => parts.find((part) => part.type === type)?.value ?? ''
  return `${get('year')}-${get('month')}-${get('day')}`
}

function ScheduleCalendar({ cards, onOpenCard, calendarLabel = 'マイスケジュールカレンダー' }: Props) {
  const today = new Date()
  const [month, setMonth] = useState(() => new Date(today.getFullYear(), today.getMonth(), 1))
  const [selectedDay, setSelectedDay] = useState<string | null>(null)
  const entries = useMemo<CalendarEntry[]>(() => cards.flatMap((card) => card.occurrences.flatMap((occurrence) => occurrence.starts_at ? [{
    key: dateKey(occurrence.starts_at), occurrenceId: occurrence.id, card,
    state: occurrence.my_schedule_state === 'committed' ? 'committed' as const : occurrence.my_schedule_state === 'checked' ? 'checked' as const : 'none' as const,
  }] : [])), [cards])
  const undated = cards.filter((card) => card.occurrences.some((occurrence) => !occurrence.starts_at))
  const year = month.getFullYear()
  const monthIndex = month.getMonth()
  const firstOffset = (new Date(year, monthIndex, 1).getDay() + 6) % 7
  const firstCell = new Date(year, monthIndex, 1 - firstOffset)
  const cells = Array.from({ length: 42 }, (_, index) => new Date(firstCell.getFullYear(), firstCell.getMonth(), firstCell.getDate() + index))
  const entriesForDay = (key: string) => entries.filter((entry) => entry.key === key).sort((left, right) => {
    const rank = { committed: 0, checked: 1, none: 2 }
    return rank[left.state] - rank[right.state] || right.card.my_schedule_count - left.card.my_schedule_count
  })
  const selectedEntries = selectedDay ? entriesForDay(selectedDay) : []
  const changeMonth = (amount: number) => { setMonth(new Date(year, monthIndex + amount, 1)); setSelectedDay(null) }

  return <section className="calendar-region" aria-label={`${year}年${monthIndex + 1}月の${calendarLabel}`}>
    {undated.length > 0 && <div className="undated-calendar"><strong>日程未定</strong>{undated.map((card) => <button type="button" key={`${card.id}-${card.occurrences[0]?.id}`} onClick={() => onOpenCard(card.id)}>{card.title}</button>)}</div>}
    <div className="calendar-month-nav"><button type="button" aria-label="前の月" onClick={() => changeMonth(-1)}>←</button><h2>{year}年{monthIndex + 1}月</h2><button type="button" aria-label="次の月" onClick={() => changeMonth(1)}>→</button></div>
    <div className="calendar-grid">
      {weekdays.map((weekday) => <div className="calendar-weekday" key={weekday}>{weekday}</div>)}
      {cells.map((date) => {
        const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
        const dayEntries = entriesForDay(key)
        const outside = date.getMonth() !== monthIndex
        return <div className={`calendar-day ${outside ? 'calendar-day--outside' : ''}`} key={key}>
          <span className="calendar-day-number">{date.getDate()}</span>
          {dayEntries.slice(0, 3).map((entry) => <button className={`calendar-event calendar-event--${entry.state}`} type="button" key={`${entry.card.id}-${entry.occurrenceId}`} title={entry.card.title} onClick={() => onOpenCard(entry.card.id)}>{entry.card.title}</button>)}
          {dayEntries.length > 3 && <button className="calendar-more" type="button" onClick={() => setSelectedDay(key)}>MORE +{dayEntries.length - 3}</button>}
        </div>
      })}
    </div>
    {selectedDay && <div className="calendar-day-list"><div><strong>{selectedDay.replaceAll('-', '/')}</strong><button type="button" aria-label="日別一覧を閉じる" onClick={() => setSelectedDay(null)}>×</button></div>{selectedEntries.map((entry) => <button type="button" key={`${entry.card.id}-${entry.occurrenceId}`} onClick={() => onOpenCard(entry.card.id)}><span>{entry.state === 'committed' ? 'コミット' : entry.state === 'checked' ? 'チェック' : '共有'}</span>{entry.card.title}</button>)}</div>}
  </section>
}

export default ScheduleCalendar
