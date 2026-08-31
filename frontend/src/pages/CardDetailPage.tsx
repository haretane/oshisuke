import { useEffect, useState } from 'react'
import { addCheckedSchedule, fetchCardDetail, fetchEditProposals, updateCard, voteEditProposal, type CardDetail, type EditProposal } from '../api/cards'
import { reportCard, type ReportReason } from '../api/reports'
import SetlistSection from '../components/SetlistSection'

type Props = { cardId: number; onBack: () => void }

const typeLabels: Record<string, string> = {
  concert: 'ライブ・コンサート', tour: 'ツアー', event: 'イベント', ticket: 'チケット',
  goods: 'グッズ発売', release: '作品発売', streaming: '配信', media: 'メディア出演',
  movie: '映画公開', other: 'その他',
}

const dateFormatter = new Intl.DateTimeFormat('ja-JP', {
  timeZone: 'Asia/Tokyo', year: 'numeric', month: 'long', day: 'numeric', weekday: 'short',
  hour: '2-digit', minute: '2-digit',
})
const reportReasons: { value: ReportReason; label: string }[] = [
  { value: 'incorrect', label: '誤った情報' }, { value: 'duplicate', label: '重複したカード' },
  { value: 'inappropriate', label: '不適切な内容' }, { value: 'unsafe_url', label: '危険または不正なURL' },
  { value: 'rights', label: '権利侵害の可能性' }, { value: 'other', label: 'その他' },
]

function CardDetailPage({ cardId, onBack }: Props) {
  const [card, setCard] = useState<CardDetail | null>(null)
  const [message, setMessage] = useState('読み込んでいます…')
  const [updatingId, setUpdatingId] = useState<number | null>(null)
  const [editing, setEditing] = useState(false)
  const [editTitle, setEditTitle] = useState('')
  const [editSummary, setEditSummary] = useState('')
  const [saving, setSaving] = useState(false)
  const [proposals, setProposals] = useState<EditProposal[]>([])
  const [votingId, setVotingId] = useState<number | null>(null)
  const [reportOpen, setReportOpen] = useState(false)
  const [reportReason, setReportReason] = useState<ReportReason>('incorrect')
  const [reportDetails, setReportDetails] = useState('')
  const [reporting, setReporting] = useState(false)
  const [reported, setReported] = useState(false)

  useEffect(() => {
    const controller = new AbortController()
    Promise.all([fetchCardDetail(cardId, controller.signal), fetchEditProposals(cardId, controller.signal)])
      .then(([cardData, proposalData]) => { setCard(cardData); setProposals(proposalData); setMessage('') })
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === 'AbortError') return
        setMessage(error instanceof Error ? error.message : 'カード詳細を取得できませんでした。')
      })
    return () => controller.abort()
  }, [cardId])

  const addSchedule = async (occurrenceId: number) => {
    setUpdatingId(occurrenceId)
    try {
      await addCheckedSchedule(occurrenceId)
      setCard((current) => current && ({
        ...current,
        occurrences: current.occurrences.map((item) => item.id === occurrenceId ? { ...item, my_schedule_state: 'checked' } : item),
      }))
    } catch (error) {
      setMessage(error instanceof Error ? error.message : '予定を追加できませんでした。')
    } finally {
      setUpdatingId(null)
    }
  }

  const startEditing = () => {
    if (!card) return
    setEditTitle(card.title)
    setEditSummary(card.summary ?? '')
    setEditing(true)
  }

  const saveCard = async () => {
    if (!card) return
    setSaving(true)
    setMessage('')
    try {
      const result = await updateCard(card.id, editTitle, editSummary)
      if (result.mode === 'updated') {
        setCard({ ...card, title: editTitle.trim(), summary: editSummary.trim() || null })
        setMessage('カードを更新しました。')
      } else {
        setMessage('編集案を提出しました。ほかのユーザーの確認後に反映されます。')
      }
      setEditing(false)
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'カードを更新できませんでした。')
    } finally {
      setSaving(false)
    }
  }

  const vote = async (proposal: EditProposal, decision: 'proposal' | 'current') => {
    setVotingId(proposal.id)
    setMessage('')
    try {
      const result = await voteEditProposal(proposal.id, decision)
      if (result.status === 'accepted') {
        setCard((current) => current && ({ ...current, title: proposal.proposed.title, summary: proposal.proposed.summary }))
        setProposals((current) => current.filter((item) => item.id !== proposal.id))
        setMessage('編集案が採用され、カードへ反映されました。')
      } else if (result.status === 'rejected') {
        setProposals((current) => current.filter((item) => item.id !== proposal.id))
        setMessage('現在の内容を残すことが決まりました。')
      } else if (result.status === 'stale') {
        setProposals((current) => current.filter((item) => item.id !== proposal.id))
        setMessage('元のカードが更新されていたため、この編集案は終了しました。')
      } else {
        setProposals((current) => current.map((item) => item.id === proposal.id ? {
          ...item, proposal_votes: result.proposal_votes, current_votes: result.current_votes,
          my_vote: decision, can_vote: false,
        } : item))
      }
    } catch (error) {
      setMessage(error instanceof Error ? error.message : '編集案を確認できませんでした。')
    } finally {
      setVotingId(null)
    }
  }

  const submitReport = async () => {
    if (!card) return
    setReporting(true)
    setMessage('')
    try {
      await reportCard(card.id, reportReason, reportDetails)
      setReportOpen(false)
      setReported(true)
      setMessage('通報を受け付けました。運営が内容を確認します。')
    } catch (error) {
      setMessage(error instanceof Error ? error.message : '通報を送信できませんでした。')
    } finally {
      setReporting(false)
    }
  }

  return (
    <div className="app-frame">
      <header className="top-row card-detail-header">
        <button className="round-action" type="button" aria-label="カード一覧へ戻る" onClick={onBack}>←</button>
        <h1>カード詳細</h1><span className="header-spacer" />
      </header>
      <main className="card-detail-content">
        {message && <p className="state-message">{message}</p>}
        {card && <article className="card-detail-card" style={{ borderColor: card.oshis[0]?.color }}>
          <header className="card-detail-hero">
            {card.thumbnail.url && <img className="detail-thumbnail" src={card.thumbnail.url} alt="" />}
            <p className="card-detail-meta">{typeLabels[card.type] ?? card.type} ・ {card.oshis.map((oshi) => oshi.name).join('・')}</p>
            {editing ? <div className="card-edit-form">
              <label>タイトル<input value={editTitle} maxLength={200} onChange={(event) => setEditTitle(event.target.value)} /></label>
              <label>内容<textarea value={editSummary} rows={6} onChange={(event) => setEditSummary(event.target.value)} /></label>
              <div className="card-edit-actions"><button className="secondary-button" type="button" disabled={saving} onClick={() => setEditing(false)}>キャンセル</button><button className="primary-button" type="button" disabled={saving || editTitle.trim() === ''} onClick={saveCard}>{saving ? '送信しています…' : card.can_edit ? '更新する' : '編集案を提出'}</button></div>
            </div> : <>
              <h2>{card.title}</h2>
              {card.summary && <p className="card-detail-summary">{card.summary}</p>}
              {card.can_edit && <button className="edit-card-button" type="button" onClick={startEditing}>情報を更新</button>}
              {card.can_propose_edit && <button className="edit-card-button" type="button" onClick={startEditing}>編集案を作る</button>}
              {card.edit_requires_email && <p className="edit-requirement">編集案はメール認証後に提出できます。</p>}
            </>}
          </header>

          <section className="detail-section">
            <h2>日程</h2>
            <div className="occurrence-list">
              {card.occurrences.map((occurrence) => {
                const state = occurrence.my_schedule_state
                return <div className="occurrence-item" key={occurrence.id}>
                  {occurrence.label && <h3>{occurrence.label}</h3>}
                  <p className="occurrence-date">{occurrence.starts_at ? dateFormatter.format(new Date(occurrence.starts_at)) : card.announced_period ?? '日程未定'}</p>
                  {occurrence.venue_name && <p>{occurrence.venue_name}</p>}
                  {occurrence.venue_address && <p className="muted-detail">{occurrence.venue_address}</p>}
                  <button className="secondary-button" type="button" disabled={state !== null || updatingId === occurrence.id} onClick={() => addSchedule(occurrence.id)}>
                    {updatingId === occurrence.id ? '追加しています…' : state === 'committed' ? 'コミット済み' : state === 'checked' ? 'チェック済み' : '⇨マイスケジュール'}
                  </button>
                </div>
              })}
            </div>
          </section>

          {card.occurrences.length > 0 && <SetlistSection occurrences={card.occurrences} />}

          <section className="detail-section">
            <h2>情報元</h2>
            {card.sources.length === 0 ? <p className="muted-detail">情報元はまだ登録されていません。</p> : <ul className="source-list">
              {card.sources.map((source) => <li key={source.id}>
                <a href={source.outbound_url} target="_blank" rel="noreferrer">{source.page_title || source.domain || 'リンクを開く'} <span>↗</span></a>
                {source.domain && <small>{source.domain}</small>}
              </li>)}
            </ul>}
          </section>
          {proposals.length > 0 && <section className="detail-section proposal-section">
            <h2>確認待ちの編集案</h2>
            {proposals.map((proposal) => <article className="proposal-item" key={proposal.id}>
              <div className="proposal-version proposal-version--current"><strong>現在の内容</strong><h3>{proposal.before.title}</h3>{proposal.before.summary && <p>{proposal.before.summary}</p>}</div>
              <div className="proposal-version proposal-version--new"><strong>編集案</strong><h3>{proposal.proposed.title}</h3>{proposal.proposed.summary && <p>{proposal.proposed.summary}</p>}</div>
              <p className="proposal-counts">現在の内容 {proposal.current_votes}票 ・ 編集案 {proposal.proposal_votes}票</p>
              {proposal.can_vote ? <div className="proposal-actions">
                <button className="secondary-button" type="button" disabled={votingId === proposal.id} onClick={() => vote(proposal, 'current')}>現在の内容が正しい</button>
                <button className="primary-button" type="button" disabled={votingId === proposal.id} onClick={() => vote(proposal, 'proposal')}>編集案が正しい</button>
              </div> : <p className="edit-requirement">{proposal.my_vote ? 'この編集案は確認済みです。' : 'この編集案には投票できません。'}</p>}
            </article>)}
          </section>}
          <section className="detail-section report-section">
            {reported ? <p className="edit-requirement">このカードは通報済みです。</p> : reportOpen ? <div className="report-form">
              <label>通報理由<select value={reportReason} onChange={(event) => setReportReason(event.target.value as ReportReason)}>{reportReasons.map((reason) => <option value={reason.value} key={reason.value}>{reason.label}</option>)}</select></label>
              <label>補足（任意）<textarea value={reportDetails} maxLength={2000} rows={4} onChange={(event) => setReportDetails(event.target.value)} /></label>
              <p className="edit-requirement">通報者のHNは一般ユーザーには表示されません。</p>
              <div className="card-edit-actions"><button className="secondary-button" type="button" disabled={reporting} onClick={() => setReportOpen(false)}>キャンセル</button><button className="primary-button" type="button" disabled={reporting} onClick={submitReport}>{reporting ? '送信しています…' : '通報する'}</button></div>
            </div> : <button className="report-open-button" type="button" onClick={() => setReportOpen(true)}>このカードを通報</button>}
          </section>
        </article>}
      </main>
    </div>
  )
}

export default CardDetailPage
