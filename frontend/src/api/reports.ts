import { protectedRequest } from './session'

export type ReportReason = 'incorrect' | 'duplicate' | 'inappropriate' | 'unsafe_url' | 'rights' | 'other'

export function reportCard(cardId: number, reason: ReportReason, details: string) {
  return protectedRequest('/api/reports', 'POST', { target_type: 'card', target_id: cardId, reason, details })
}
