import { protectedRequest } from './session'

export type ReportStatus = 'open' | 'reviewing' | 'resolved' | 'dismissed'
export type AdminReport = {
  id: number
  target_type: string
  target_id: number
  target_title: string | null
  reason: string
  details: string | null
  status: ReportStatus
  created_at: string
}

export async function fetchAdminReports(status: ReportStatus, signal: AbortSignal) {
  const response = await fetch(`/api/admin/reports?status=${status}`, { signal })
  if (!response.ok) throw new Error(response.status === 403 ? '運営権限が必要です。' : '通報一覧を取得できませんでした。')
  return ((await response.json()) as { data: AdminReport[] }).data
}

export function updateReportStatus(reportId: number, status: Exclude<ReportStatus, 'open'>) {
  return protectedRequest(`/api/admin/reports/${reportId}`, 'PATCH', { status })
}
