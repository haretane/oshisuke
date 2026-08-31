import { protectedRequest } from './session'

export type UrlPreview = {
  token: string
  page_title: string | null
  resolved_url: string
  domain: string
  thumbnail_url: string | null
}

export async function createUrlPreview(url: string) {
  const body = await protectedRequest('/api/url-previews', 'POST', { url }) as { data: UrlPreview }
  return body.data
}
