import { formatAmount, useTransactionStatus } from '../hooks/useTransactionStatus'

export default function SuccessPage() {
  const { tranId, status, loading } = useTransactionStatus({ poll: true })
  return (
    <main className="page">
      <div className="card result">
        <div className="icon">✅</div>
        <h2>Payment Successful</h2>
        <p className="hint">Your payment has been completed.</p>
        {loading ? (
          <p className="hint">Loading transaction details…</p>
        ) : (
          <>
            <div className="detail">
              <div className="row">
                <span>Transaction ID</span>
                <span>{tranId || '—'}</span>
              </div>
              <div className="row">
                <span>Amount</span>
                <span>{formatAmount(status?.amount, status?.currency) || '—'}</span>
              </div>
              <div className="row">
                <span>Status</span>
                <span>{status?.status === 'SUCCESS' ? 'Successful' : '—'}</span>
              </div>
            </div>
          </>
        )}
      </div>
    </main>
  )
}