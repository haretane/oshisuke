import { useState, type FormEvent } from 'react'
import { loginAccount, registerAccount, type SessionData } from '../api/session'

type Props = { onAuthenticated: (session: SessionData) => void }

function AuthPage({ onAuthenticated }: Props) {
  const [mode, setMode] = useState<'login' | 'register'>('register')
  const [handleName, setHandleName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [message, setMessage] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSubmitting(true)
    setMessage('')
    try {
      const session = mode === 'register'
        ? await registerAccount(handleName, email, password)
        : await loginAccount(email, password)
      onAuthenticated(session)
    } catch (error) {
      setMessage(error instanceof Error ? error.message : '処理を完了できませんでした。')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="auth-page">
      <div className="brand-logo" aria-label="推しスケ">OSHISUKE</div>
      <h1>{mode === 'register' ? 'アカウントを作成' : 'ログイン'}</h1>
      <p className="auth-lead">推しの予定を、自分のスケジュールへ。</p>
      <form className="auth-form" onSubmit={submit}>
        {mode === 'register' && <label>HN<input value={handleName} maxLength={50} autoComplete="nickname" required onChange={(event) => setHandleName(event.target.value)} /></label>}
        <label>メールアドレス<input type="email" value={email} maxLength={255} autoComplete="email" required onChange={(event) => setEmail(event.target.value)} /></label>
        <label>パスワード<input type="password" value={password} minLength={10} maxLength={128} autoComplete={mode === 'register' ? 'new-password' : 'current-password'} required onChange={(event) => setPassword(event.target.value)} /></label>
        {mode === 'register' && <p className="field-help">10文字以上で設定してください。</p>}
        {message && <p className="auth-error" role="alert">{message}</p>}
        <button className="primary-button" type="submit" disabled={submitting}>{submitting ? '送信中…' : mode === 'register' ? 'アカウントを作成' : 'ログイン'}</button>
      </form>
      <button className="text-button" type="button" onClick={() => { setMode(mode === 'register' ? 'login' : 'register'); setMessage('') }}>{mode === 'register' ? 'すでにアカウントをお持ちの方' : 'アカウントを作成する'}</button>
    </main>
  )
}

export default AuthPage

