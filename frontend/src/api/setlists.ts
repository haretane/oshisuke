import { protectedRequest } from './session'

export type SetlistItemType = 'song' | 'mc' | 'op' | 'se' | 'ed' | 'other'

export type SetlistItem = {
  id: number
  type: SetlistItemType
  title: string
  song_number: number | null
}

export type Setlist = {
  id: number
  occurrence_id: number
  items: SetlistItem[]
  can_edit: boolean
}

export async function fetchSetlist(occurrenceId: number, signal?: AbortSignal) {
  const response = await fetch(`/api/occurrences/${occurrenceId}/setlist`, { signal })
  if (!response.ok) throw new Error('セットリストを取得できませんでした。')
  return (await response.json() as { data: { setlist: Setlist | null; can_create: boolean } }).data
}

export async function saveSetlist(occurrenceId: number, items: { type: SetlistItemType; title: string }[]) {
  return protectedRequest(`/api/occurrences/${occurrenceId}/setlist`, 'PUT', { items }) as Promise<{ data: { setlist: Setlist } }>
}
