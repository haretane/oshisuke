import { protectedRequest } from './session'

export type OshiType = 'person' | 'group' | 'work' | 'character' | 'other'

export type OshiSummary = {
  id: number
  name: string
  short_name: string | null
  name_kana: string | null
  type: OshiType
  categories: string[]
  icon_key: string
  color: string
  is_following: boolean
  follower_count: number
}

type OshiListResponse = {
  data: OshiSummary[]
  meta: { next_cursor: string | null }
}

type ErrorResponse = { error?: { message?: string } }

export async function fetchOshis(
  query: string,
  type: OshiType | '',
  signal: AbortSignal,
): Promise<OshiSummary[]> {
  const parameters = new URLSearchParams()
  if (query.trim()) parameters.set('q', query.trim())
  if (type) parameters.set('type', type)

  const queryString = parameters.toString()
  const response = await fetch(`/api/oshis${queryString ? `?${queryString}` : ''}`, {
    signal,
  })

  if (!response.ok) {
    const body = (await response.json().catch(() => ({}))) as ErrorResponse
    throw new Error(body.error?.message ?? '推し情報を取得できませんでした。')
  }

  const body = (await response.json()) as OshiListResponse
  return body.data
}

export function updateFollowing(oshiId: number, follow: boolean) {
  return protectedRequest(`/api/me/oshis/${oshiId}`, follow ? 'PUT' : 'DELETE')
}

export type OshiProfile = OshiSummary & {
  alternate_names: string[]
  type_label: string
  description: string | null
  has_custom_appearance: boolean
  links: { id: number; type: string; label: string; url: string }[]
}

export async function fetchOshiProfile(oshiId: number, signal: AbortSignal): Promise<OshiProfile> {
  const response = await fetch(`/api/oshis/${oshiId}`, { signal })
  if (!response.ok) throw new Error('推しプロフィールを取得できませんでした。')
  return (await response.json() as { data: OshiProfile }).data
}

export function createOshi(input: { name: string; short_name: string; name_kana: string; type: OshiType; description: string; icon_key: string; color: string }) {
  return protectedRequest('/api/oshis', 'POST', input) as Promise<{ data: { id: number } }>
}

export async function updateMyOshiAppearance(oshiId: number, input: { icon_key: string; color: string } | { reset: true }) {
  const response = await protectedRequest(`/api/me/oshis/${oshiId}`, 'PATCH', input) as { data: { icon_key: string; color: string; has_custom_appearance: boolean } }
  return response.data
}
