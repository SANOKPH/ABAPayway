import { Link } from 'react-router-dom'
import { useTransactionStatus } from '../hooks/useTransactionStatus'

export default function FailedPage() {
  const { tranId, loading } = useTransactionStatus()

  return (
    <main className="page">
      <div className="card result">
        <div className="icon">❌</div>
        <h2>Payment Failed</h2>
        <p className="hint">Your payment could not be completed.</p>

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