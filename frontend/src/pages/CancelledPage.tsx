import { Link } from 'react-router-dom'
import { useTransactionStatus } from '../hooks/useTransactionStatus'

export default function CancelledPage() {
  const { tranId, loading } = useTransactionStatus()

  return (
    <main className="page">
      <div className="card result">
        <div className="icon">🚫</div>
        <h2>Payment Cancelled</h2>
        <p className="hint">You cancelled the payment.</p>

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