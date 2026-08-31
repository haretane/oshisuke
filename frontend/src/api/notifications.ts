import { protectedRequest } from './session'

export type NotificationItem = {
  id: number
  type: string
  card_id: number | null
  title: string
  body: string
  read: boolean
  created_at: string
}

export async function fetchNotifications(signal?: AbortSignal) {
  const response = await fetch('/api/me/notifications', { signal })
  if (!response.ok) throw new Error('お知らせを取得できませんでした。')
  return (await response.json()) as { data: NotificationItem[]; meta: { unread_count: number } }
}

export function readNotification(notificationId: number) {
  return protectedRequest(`/api/me/notifications/${notificationId}/read`, 'POST')
}

export function readAllNotifications() {
  return protectedRequest('/api/me/notifications/read-all', 'POST')
}
