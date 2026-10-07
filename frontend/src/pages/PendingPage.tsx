import { useEffect } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { fetchTransactionStatus } from '../api/client'
import { useTransactionStatus } from '../hooks/useTransactionStatus'

const POLL_INTERVAL_MS = 4000
const MAX_POLLS = 15

export default function PendingPage() {
  const { tranId, loading } = useTransactionStatus()
  const navigate = useNavigate()

  useEffect(() => {
    if (!tranId || loading) return

    let cancelled = false
    let tries = 0

    const interval = window.setInterval(async () => {
      tries += 1

      try {
        const data = await fetchTransactionStatus(tranId)
        if (cancelled) return

        if (data.status === 'SUCCESS') {
          navigate(`/payment/success?tran_id=${encodeURIComponent(tranId)}`, { replace: true })
        } else if (data.status === 'FAILED') {
          navigate(`/payment/failed?tran_id=${encodeURIComponent(tranId)}`, { replace: true })
        } else if (data.status === 'CANCELLED') {
          navigate(`/payment/cancelled?tran_id=${encodeURIComponent(tranId)}`, { replace: true })
        }
      } catch {
        // transient network error — keep polling
      }

      if (tries >= MAX_POLLS) {
        window.clearInterval(interval)
      }
    }, POLL_INTERVAL_MS)

    return () => {
      cancelled = true
      window.clearInterval(interval)
    }
  }, [tranId, loading, navigate])

  return (
    <main className="page">
      <div className="card result">
        <div className="icon">⏳</div>
        <h2>Payment Pending</h2>
        <p className="hint">
          Your payment is still being processed. The final result will be
          confirmed by PayWay shortly.
        </p>

        <div className="detail">
          <div className="row">
            <span>Transaction ID</span>
            <span>{loading ? '…' : tranId || '—'}</span>
          </div>
        </div>

        <Link className="link" to="/">
          Try Again
        </Link>
      </div>
    </main>
  )
}