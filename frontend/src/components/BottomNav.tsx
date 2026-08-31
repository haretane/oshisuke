import { ScheduleIcon, SearchIcon } from './Icons'

export type MainPage = 'search' | 'home' | 'schedule'

type BottomNavProps = {
  current: MainPage
  onNavigate: (page: MainPage) => void
}

function BottomNav({ current, onNavigate }: BottomNavProps) {
  return (
    <nav className="bottom-nav" aria-label="メインナビゲーション">
      <button className={`nav-item ${current === 'search' ? 'nav-item--active' : ''}`} type="button" aria-current={current === 'search' ? 'page' : undefined} onClick={() => onNavigate('search')}>
        <SearchIcon /><span>推し検索</span>
      </button>
      <button className={`nav-item ${current === 'home' ? 'nav-item--active' : ''}`} type="button" aria-current={current === 'home' ? 'page' : undefined} onClick={() => onNavigate('home')}>
        <span className="home-icon" aria-hidden="true">OSI</span><span>ホーム</span>
      </button>
      <button className={`nav-item ${current === 'schedule' ? 'nav-item--active' : ''}`} type="button" aria-current={current === 'schedule' ? 'page' : undefined} onClick={() => onNavigate('schedule')}>
        <ScheduleIcon /><span>マイスケジュール</span>
      </button>
    </nav>
  )
}

export default BottomNav

