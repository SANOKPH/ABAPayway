import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { fetchTransactionStatus, TransactionStatus } from '../api/client'

export interface TransactionState {
  tranId: string
  status: TransactionStatus | null
  loading: boolean
}

interface Options {
  poll?: boolean
  intervalMs?: number
  maxAttempts?: number
}

/**
 * Load the (safe, minimal) transaction status for a result page.
 * Read-only lookup — this can never change the transaction.
 *
 * When `poll` is true the status is refreshed until it reaches a final
 * state (SUCCESS / FAILED / CANCELLED), which covers the brief window where
 * the browser arrives before the server-to-server callback has been applied.
 */
export function useTransactionStatus(options: Options = {}): TransactionState {
  const { poll = false, intervalMs = 3000, maxAttempts = 20 } = options
  const [params] = useSearchParams()
  const tranId = params.get('tran_id') ?? ''
  const [status, setStatus] = useState<TransactionStatus | null>(null)
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    if (!tranId) {
      setStatus(null)
      setLoading(false)
      return
    }

    let active = true
    let interval: number | undefined
    let pollCount = 0

    const load = async (): Promise<void> => {
      try {
        const data = await fetchTransactionStatus(tranId)
        if (!active) return

        setStatus(data)
        const isFinal = ['SUCCESS', 'FAILED', 'CANCELLED'].includes(data.status)
        if (isFinal || !poll || pollCount >= maxAttempts) {
          window.clearInterval(interval)
          return
        }
      } catch {
        if (!active) return
        if (!poll || pollCount >= maxAttempts) {
          window.clearInterval(interval)
          return
        }
      }

      pollCount += 1
    }

    setLoading(true)
    load().finally(() => {
      if (active) setLoading(false)
    })

    if (poll) {
      interval = window.setInterval(load, intervalMs)
    }

    return () => {
      active = false
      window.clearInterval(interval)
    }
  }, [tranId, poll, intervalMs, maxAttempts])

  return { tranId, status, loading }
}

export function formatAmount(amount: string | undefined, currency: string | undefined): string {
  if (!amount || !currency) return ''
  const number = Number(amount)
  const formatted = Number.isInteger(number)
    ? number.toLocaleString('en-US')
    : number.toLocaleString('en-US', { minimumFractionDigits: 2 })
  return `${formatted} ${currency}`
}