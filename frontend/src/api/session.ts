export type SessionData = {
  authenticated: boolean
  user: { id: number; handle_name: string; role: 'user' | 'moderator' | 'admin' } | null
  csrf_token: string
}

type SessionResponse = { data: SessionData }
type ApiError = { error?: { message?: string; fields?: Record<string, string> } }

let sessionPromise: Promise<SessionData> | null = null

export function getSession(force = false) {
  if (force) sessionPromise = null
  if (!sessionPromise) {
    sessionPromise = fetch('/api/session')
      .then((response) => {
        if (!response.ok) throw new Error('セッションを開始できませんでした。')
        return response.json() as Promise<SessionResponse>
      })
      .then((body) => body.data)
      .catch((error) => {
        sessionPromise = null
        throw error
      })
  }
  return sessionPromise
}

async function authRequest(url: string, body: unknown) {
  const session = await getSession()
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.csrf_token },
    body: JSON.stringify(body),
  })
  if (!response.ok) {
    const errorBody = await response.json().catch(() => ({})) as ApiError
    throw new Error(errorBody.error?.message ?? '認証処理を完了できませんでした。')
  }
  return getSession(true)
}

export function registerAccount(handleName: string, email: string, password: string) {
  return authRequest('/api/auth/register', { handle_name: handleName, email, password })
}

export function loginAccount(email: string, password: string) {
  return authRequest('/api/auth/login', { email, password })
}

export async function logoutAccount() {
  await protectedRequest('/api/auth/logout', 'POST')
  return getSession(true)
}

export async function protectedRequest(url: string, method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', body?: unknown) {
  const session = await getSession()
  if (!session.authenticated) throw new Error('ログインが必要です。')
  const response = await fetch(url, {
    method,
    headers: {
      'X-CSRF-Token': session.csrf_token,
      ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  if (!response.ok) {
    const errorBody = await response.json().catch(() => ({})) as ApiError
    throw new Error(errorBody.error?.message ?? '操作を完了できませんでした。')
  }
  return response.status === 204 ? null : response.json()
}
