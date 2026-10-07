export const API_BASE_URL =
  (import.meta.env.VITE_API_URL as string | undefined)?.replace(/\/$/, '') ??
  'http://localhost:8000'

export interface CheckoutFields {
  req_time: string
  merchant_id: string
  tran_id: string
  amount: string
  hash: string
  [key: string]: string | number
}

export interface PaymentResponse {
  transaction_id: string
  checkout: {
    action: string
    fields: CheckoutFields
  }
}

export interface TransactionStatus {
  tran_id: string
  amount: string
  currency: string
  status: 'PENDING' | 'SUCCESS' | 'FAILED' | 'CANCELLED'
}

export async function createPayment(input: {
  name: string
  account_number: string
  amount: number
}): Promise<PaymentResponse> {
  const response = await fetch(`${API_BASE_URL}/api/payments`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify(input),
  })

  if (!response.ok) {
    const body = await response.json().catch(() => ({}))
    const message = Array.isArray(body.errors)
      ? body.errors.join(', ')
      : Array.isArray(body.message)
        ? body.message[0]
        : typeof body.message === 'string'
          ? body.message
          : 'Payment could not be completed. Please try again later.'
    throw new Error(message)
  }

  return response.json()
}

export async function fetchTransactionStatus(tranId: string): Promise<TransactionStatus> {
  const response = await fetch(`${API_BASE_URL}/api/payments/${encodeURIComponent(tranId)}`, {
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) {
    throw new Error('Transaction not found.')
  }

  return response.json()
}

/**
 * Submit the server-generated PayWay purchase form directly to PayWay.
 * This mirrors ABA's documented HTML form pattern: PUT fields as hidden
 * inputs and let the browser POST to the PayWay checkout.
 */
export function submitToPayWay(action: string, fields: CheckoutFields): void {
  const form = document.createElement('form')
  form.method = 'POST'
  form.action = action
  form.style.display = 'none'

  for (const [key, value] of Object.entries(fields)) {
    const input = document.createElement('input')
    input.type = 'hidden'
    input.name = key
    input.value = String(value)
    form.appendChild(input)
  }

  document.body.appendChild(form)
  form.submit()
}