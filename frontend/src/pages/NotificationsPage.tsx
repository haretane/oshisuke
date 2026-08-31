import { useEffect, useState } from 'react'
import { fetchNotifications, readAllNotifications, readNotification, type NotificationItem } from '../api/notifications'

type Props = { onBack: () => void; onOpenCard: (cardId: number) => void; onUnreadChange: (count: number) => void }

const dateFormatter = new Intl.DateTimeFormat('ja-JP', { timeZone: 'Asia/Tokyo', month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' })

function NotificationsPage({ onBack, onOpenCard, onUnreadChange }: Props) {
  const [items, setItems] = useState<NotificationItem[]>([])
  const [message, setMessage] = useState('読み込んでいます…')

  useEffect(() => {
    const controller = new AbortController()
    fetchNotifications(controller.signal)
      .then((response) => { setItems(response.data); onUnreadChange(response.meta.unread_count); setMessage(response.data.length === 0 ? 'お知らせはまだありません' : '') })
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') return
        setMessage(error instanceof Error ? error.message : 'お知らせを取得できませんでした。')
      })
    return () => controller.abort()
  }, [onUnreadChange])

  const readAll = async () => {
    try {
      await readAllNotifications()
      setItems((current) => current.map((item) => ({ ...item, read: true })))
      onUnreadChange(0)
    } catch (error) {
      setMessage(error instanceof Error ? error.message : '既読にできませんでした。')
    }
  }

  const openItem = async (item: NotificationItem) => {
    try {
      if (!item.read) {
        await readNotification(item.id)
        setItems((current) => current.map((currentItem) => currentItem.id === item.id ? { ...currentItem, read: true } : currentItem))
        onUnreadChange(items.filter((currentItem) => !currentItem.read && currentItem.id !== item.id).length)
      }
      if (item.card_id !== null) onOpenCard(item.card_id)
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'お知らせを開けませんでした。')
    }
  }

  return <div className="app-frame">
    <header className="top-row notification-header"><button className="round-action" type="button" aria-label="前の画面へ戻る" onClick={onBack}>←</button><h1>お知らせ</h1><button className="read-all-button" type="button" disabled={!items.some((item) => !item.read)} onClick={readAll}>すべて既読</button></header>
    <main className="notification-list">{message && <p className="state-message">{message}</p>}{items.map((item) => <button className={`notification-item ${item.read ? '' : 'notification-item--unread'}`} type="button" key={item.id} onClick={() => openItem(item)}><strong>{item.title}</strong><span>{item.body}</span><small>{dateFormatter.format(new Date(item.created_at))}</small></button>)}</main>
  </div>
}

export default NotificationsPage
