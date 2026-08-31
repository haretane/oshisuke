import { useEffect, useState } from 'react'
import { fetchAdminReports, updateReportStatus, type AdminReport, type ReportStatus } from '../api/admin'

type Props = { onBack: () => void; onOpenCard: (cardId: number) => void }
const statuses: { value: ReportStatus; label: string }[] = [
  { value: 'open', label: '未対応' }, { value: 'reviewing', label: '対応中' },
  { value: 'resolved', label: '解決' }, { value: 'dismissed', label: '却下' },
]
const reasonLabels: Record<string, string> = { incorrect: '誤った情報', duplicate: '重複したカード', inappropriate: '不適切な内容', unsafe_url: '危険または不正なURL', rights: '権利侵害の可能性', other: 'その他' }

function AdminReportsPage({ onBack, onOpenCard }: Props) {
  const [status, setStatus] = useState<ReportStatus>('open')
  const [reports, setReports] = useState<AdminReport[]>([])
  const [message, setMessage] = useState('読み込んでいます…')
  const [updatingId, setUpdatingId] = useState<number | null>(null)

  useEffect(() => {
    const controller = new AbortController()
    fetchAdminReports(status, controller.signal).then((data) => { setReports(data); setMessage(data.length === 0 ? '該当する通報はありません' : '') }).catch((error: unknown) => {
      if (error instanceof DOMException && error.name === 'AbortError') return
      setMessage(error instanceof Error ? error.message : '通報一覧を取得できませんでした。')
    })
    return () => controller.abort()
  }, [status])

  const updateStatus = async (report: AdminReport, nextStatus: 'reviewing' | 'resolved' | 'dismissed') => {
    setUpdatingId(report.id)
    try {
      await updateReportStatus(report.id, nextStatus)
      setReports((current) => current.filter((item) => item.id !== report.id))
    } catch (error) {
      setMessage(error instanceof Error ? error.message : '状態を更新できませんでした。')
    } finally {
      setUpdatingId(null)
    }
  }

  return <div className="app-frame">
    <header className="top-row card-detail-header"><button className="round-action" type="button" aria-label="前の画面へ戻る" onClick={onBack}>←</button><h1>通報管理</h1><span className="header-spacer" /></header>
    <div className="admin-status-tabs">{statuses.map((item) => <button type="button" aria-pressed={status === item.value} key={item.value} onClick={() => { setMessage('読み込んでいます…'); setStatus(item.value) }}>{item.label}</button>)}</div>
    <main className="admin-report-list">{message && <p className="state-message">{message}</p>}{reports.map((report) => <article className="admin-report" key={report.id}><div><span>#{report.id}</span><strong>{reasonLabels[report.reason] ?? report.reason}</strong></div><h2>{report.target_title ?? `対象ID ${report.target_id}`}</h2>{report.details && <p>{report.details}</p>}<button className="text-button admin-target-button" type="button" onClick={() => onOpenCard(report.target_id)}>対象カードを確認</button><div className="admin-report-actions"><button type="button" disabled={updatingId === report.id} onClick={() => updateStatus(report, 'reviewing')}>対応中</button><button type="button" disabled={updatingId === report.id} onClick={() => updateStatus(report, 'resolved')}>解決</button><button type="button" disabled={updatingId === report.id} onClick={() => updateStatus(report, 'dismissed')}>却下</button></div></article>)}</main>
  </div>
}

export default AdminReportsPage
