import type { SessionData } from '../api/session'

type Props = { session: SessionData; busy: boolean; onClose: () => void; onLogout: () => void; onAdmin: () => void }

function AccountMenu({ session, busy, onClose, onLogout, onAdmin }: Props) {
  return (
    <div className="modal-backdrop" role="presentation" onMouseDown={onClose}>
      <section className="account-menu" role="dialog" aria-modal="true" aria-labelledby="account-title" onMouseDown={(event) => event.stopPropagation()}>
        <button className="modal-close" type="button" aria-label="閉じる" onClick={onClose}>×</button>
        <h2 id="account-title">アカウント</h2>
        <p>{session.user?.handle_name}</p>
        {session.user && ['admin', 'moderator'].includes(session.user.role) && <button className="secondary-button account-admin-button" type="button" onClick={onAdmin}>運営管理</button>}
        <button className="secondary-button" type="button" disabled={busy} onClick={onLogout}>{busy ? 'ログアウト中…' : 'ログアウト'}</button>
      </section>
    </div>
  )
}

export default AccountMenu
