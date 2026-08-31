import { protectedRequest } from './session'

export type ScheduleState = 'checked' | 'committed' | null
export type CardSort = 'new' | 'date' | 'popular'
export type CardType = 'concert' | 'tour' | 'event' | 'ticket' | 'goods' | 'release' | 'streaming' | 'media' | 'movie' | 'other'

export type Occurrence = {
  id: number
  label: string | null
  starts_at: string | null
  ends_at: string | null
  display_timezone: string
  venue_name: string | null
  venue_address?: string | null
  status: string
  my_schedule_state: ScheduleState
}

export type CardSource = {
  id: number
  role: string
  page_title: string | null
  domain: string
  outbound_url: string
}

export type CardDetail = Omit<CardSummary, 'primary_source' | 'my_schedule_count'> & {
  sources: CardSource[]
  hashtags: string[]
  can_edit: boolean
  can_propose_edit: boolean
  edit_requires_email: boolean
}

export type EditProposal = {
  id: number
  before: { title: string; summary: string | null }
  proposed: { title: string; summary: string | null }
  proposal_votes: number
  current_votes: number
  my_vote: 'proposal' | 'current' | null
  can_vote: boolean
  created_at: string
}

export type CardSummary = {
  id: number
  type: string
  title: string
  summary: string | null
  schedule_status: string
  announced_period: string | null
  thumbnail: { url: string | null; placeholder_key: string }
  oshis: { id: number; name: string; icon_key: string; color: string }[]
  occurrences: Occurrence[]
  primary_source: { page_title: string | null; domain: string; outbound_url: string } | null
  my_schedule_count: number
  updated_at: string
}

type CardListResponse = { data: CardSummary[] }
type ErrorResponse = { error?: { message?: string } }

async function fetchCardList(url: string, signal: AbortSignal): Promise<CardSummary[]> {
  const response = await fetch(url, { signal })
  if (!response.ok) {
    const body = (await response.json().catch(() => ({}))) as ErrorResponse
    throw new Error(body.error?.message ?? 'カード情報を取得できませんでした。')
  }
  return ((await response.json()) as CardListResponse).data
}

export function fetchHomeCards(sort: CardSort, signal: AbortSignal, oshiId?: number) {
  const parameters = new URLSearchParams({ scope: 'following', sort })
  if (oshiId) parameters.set('oshi_id', String(oshiId))
  return fetchCardList(`/api/cards?${parameters}`, signal)
}

export async function fetchCardDetail(cardId: number, signal: AbortSignal): Promise<CardDetail> {
  const response = await fetch(`/api/cards/${cardId}`, { signal })
  if (!response.ok) {
    const body = (await response.json().catch(() => ({}))) as ErrorResponse
    throw new Error(body.error?.message ?? 'カード詳細を取得できませんでした。')
  }
  return ((await response.json()) as { data: CardDetail }).data
}

export async function fetchEditProposals(cardId: number, signal: AbortSignal): Promise<EditProposal[]> {
  const response = await fetch(`/api/cards/${cardId}/edit-proposals`, { signal })
  if (!response.ok) throw new Error('編集案を取得できませんでした。')
  return ((await response.json()) as { data: EditProposal[] }).data
}

export async function voteEditProposal(proposalId: number, decision: 'proposal' | 'current') {
  const body = await protectedRequest(`/api/edit-proposals/${proposalId}/votes`, 'POST', { decision }) as {
    data: { status: 'pending' | 'accepted' | 'rejected' | 'stale'; proposal_votes: number; current_votes: number }
  }
  return body.data
}

export function fetchScheduleCards(
  state: Exclude<ScheduleState, null>,
  signal: AbortSignal,
) {
  return fetchCardList(`/api/me/schedules?state=${state}&period=future`, signal)
}

export function addCheckedSchedule(occurrenceId: number) {
  return protectedRequest(`/api/me/schedules/${occurrenceId}`, 'PUT', { state: 'checked' })
}

export function setScheduleState(occurrenceId: number, state: Exclude<ScheduleState, null>) {
  return protectedRequest(`/api/me/schedules/${occurrenceId}`, 'PUT', { state })
}

export function removeSchedule(occurrenceId: number) {
  return protectedRequest(`/api/me/schedules/${occurrenceId}`, 'DELETE')
}

export type MyOshi = { id: number; name: string; short_name: string | null; type: string; icon_key: string; color: string }
export type CreateCardInput = {
  type: CardType
  oshi_id: number
  title: string
  summary: string
  occurrences: { label: string; starts_at: string | null; venue_name: string }[]
  source_url: string
  preview_token: string
  use_thumbnail: boolean
}

export async function fetchMyOshis(signal: AbortSignal): Promise<MyOshi[]> {
  const response = await fetch('/api/me/oshis', { signal })
  if (!response.ok) throw new Error('登録中の推しを取得できませんでした。')
  return ((await response.json()) as { data: MyOshi[] }).data
}

export async function createCard(input: CreateCardInput) {
  const body = await protectedRequest('/api/cards', 'POST', input) as { data: { id: number; occurrence_ids: number[]; source_id: number | null } }
  return body.data
}

export async function updateCard(cardId: number, title: string, summary: string) {
  const body = await protectedRequest(`/api/cards/${cardId}`, 'PATCH', { title, summary }) as { data: { mode: 'updated' | 'proposal' } }
  return body.data
}
