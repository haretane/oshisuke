import { useEffect, useState } from 'react'
import { getSession, logoutAccount, type SessionData } from './api/session'
import { fetchNotifications } from './api/notifications'
import AccountMenu from './components/AccountMenu'
import type { MainPage } from './components/BottomNav'
import AuthPage from './pages/AuthPage'
import AdminReportsPage from './pages/AdminReportsPage'
import CardCreatePage from './pages/CardCreatePage'
import CardDetailPage from './pages/CardDetailPage'
import HomePage from './pages/HomePage'
import MySchedulePage from './pages/MySchedulePage'
import NotificationsPage from './pages/NotificationsPage'
import OshiProfilePage from './pages/OshiProfilePage'
import OshiCreatePage from './pages/OshiCreatePage'
import SearchPage from './pages/SearchPage'

function App() {
  const initialCardId = Number(window.location.pathname.match(/^\/cards\/(\d+)\/?$/)?.[1] ?? 0)
  const initialOshiId = Number(window.location.pathname.match(/^\/oshis\/(\d+)\/?$/)?.[1] ?? 0)
  const [page, setPage] = useState<MainPage | 'create' | 'detail' | 'oshi-profile' | 'oshi-create' | 'notifications' | 'admin'>(initialCardId > 0 ? 'detail' : initialOshiId > 0 ? 'oshi-profile' : 'home')
  const [detailCardId, setDetailCardId] = useState(initialCardId)
  const [detailOshiId, setDetailOshiId] = useState(initialOshiId)
  const [createOshiId, setCreateOshiId] = useState<number | undefined>()
  const [session, setSession] = useState<SessionData | null>(null)
  const [sessionError, setSessionError] = useState('')
  const [accountOpen, setAccountOpen] = useState(false)
  const [loggingOut, setLoggingOut] = useState(false)
  const [unreadCount, setUnreadCount] = useState(0)
  const [notificationReturnPage, setNotificationReturnPage] = useState<MainPage>('home')

  useEffect(() => {
    getSession()
      .then(setSession)
      .catch((error: unknown) => setSessionError(error instanceof Error ? error.message : 'セッションを確認できませんでした。'))
  }, [])

  useEffect(() => {
    if (!session?.authenticated) return
    const controller = new AbortController()
    fetchNotifications(controller.signal).then((response) => setUnreadCount(response.meta.unread_count)).catch(() => undefined)
    return () => controller.abort()
  }, [session?.authenticated])

  useEffect(() => {
    const restoreFromUrl = () => {
      const cardId = Number(window.location.pathname.match(/^\/cards\/(\d+)\/?$/)?.[1] ?? 0)
      if (cardId > 0) {
        setDetailCardId(cardId)
        setPage('detail')
      } else {
        const oshiId = Number(window.location.pathname.match(/^\/oshis\/(\d+)\/?$/)?.[1] ?? 0)
        if (oshiId > 0) { setDetailOshiId(oshiId); setPage('oshi-profile'); return }
        setPage('home')
      }
    }
    window.addEventListener('popstate', restoreFromUrl)
    return () => window.removeEventListener('popstate', restoreFromUrl)
  }, [])

  if (sessionError) return <main className="startup-message">{sessionError}</main>
  if (!session) return <main className="startup-message">読み込んでいます…</main>
  if (!session.authenticated) return <AuthPage onAuthenticated={setSession} />

  const openAccount = () => setAccountOpen(true)
  const openNotifications = (returnPage: MainPage) => {
    setNotificationReturnPage(returnPage)
    setPage('notifications')
  }
  const openCard = (cardId: number) => {
    window.history.pushState({ oshisukeDetail: true }, '', `/cards/${cardId}`)
    setDetailCardId(cardId)
    setPage('detail')
  }
  const closeCard = () => {
    if (window.history.state?.oshisukeDetail) {
      window.history.back()
      return
    }
    window.history.replaceState(null, '', '/')
    setPage('home')
  }
  const openOshi = (oshiId: number) => {
    window.history.pushState({ oshisukeOshi: true }, '', `/oshis/${oshiId}`)
    setDetailOshiId(oshiId)
    setPage('oshi-profile')
  }
  const openCreate = (oshiId?: number) => { setCreateOshiId(oshiId); setPage('create') }
  const closeOshi = () => {
    if (window.history.state?.oshisukeOshi) { window.history.back(); return }
    window.history.replaceState(null, '', '/')
    setPage('home')
  }
  const logout = async () => {
    setLoggingOut(true)
    try {
      setSession(await logoutAccount())
      setUnreadCount(0)
      setAccountOpen(false)
    } finally {
      setLoggingOut(false)
    }
  }

  const content = page === 'detail'
    ? <CardDetailPage cardId={detailCardId} onBack={closeCard} />
    : page === 'oshi-profile'
      ? <OshiProfilePage oshiId={detailOshiId} onBack={closeOshi} onCreateCard={() => openCreate(detailOshiId)} />
    : page === 'oshi-create'
      ? <OshiCreatePage onClose={() => setPage('search')} onCreated={openOshi} />
    : page === 'admin'
      ? <AdminReportsPage onBack={() => setPage('home')} onOpenCard={openCard} />
    : page === 'notifications'
      ? <NotificationsPage onBack={() => setPage(notificationReturnPage)} onOpenCard={openCard} onUnreadChange={setUnreadCount} />
    : page === 'create'
    ? <CardCreatePage initialOshiId={createOshiId} onClose={() => setPage('home')} onCreated={() => setPage('home')} />
    : page === 'search'
    ? <SearchPage onNavigate={setPage} onAccount={openAccount} onNotifications={() => openNotifications('search')} onOpenOshi={openOshi} onCreateOshi={() => setPage('oshi-create')} unreadCount={unreadCount} />
    : page === 'schedule'
      ? <MySchedulePage onNavigate={setPage} onAccount={openAccount} onOpenCard={openCard} onNotifications={() => openNotifications('schedule')} unreadCount={unreadCount} />
      : <HomePage onNavigate={setPage} onAccount={openAccount} onCreate={() => openCreate()} onOpenCard={openCard} onNotifications={() => openNotifications('home')} unreadCount={unreadCount} />

  return <>{content}{accountOpen && <AccountMenu session={session} busy={loggingOut} onClose={() => setAccountOpen(false)} onLogout={logout} onAdmin={() => { setAccountOpen(false); setPage('admin') }} />}</>
}

export default App
