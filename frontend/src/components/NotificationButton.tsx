import { BellIcon } from './Icons'

type Props = { unreadCount: number; onClick: () => void }

function NotificationButton({ unreadCount, onClick }: Props) {
  return <button className="round-action notification-button" type="button" aria-label={`お知らせ${unreadCount > 0 ? `、未読${unreadCount}件` : ''}`} onClick={onClick}><BellIcon />{unreadCount > 0 && <span aria-hidden="true">{unreadCount > 99 ? '99+' : unreadCount}</span>}</button>
}

export default NotificationButton
