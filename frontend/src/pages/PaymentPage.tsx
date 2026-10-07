import { FormEvent, useState } from 'react'
import { createPayment, submitToPayWay } from '../api/client'

interface FieldErrors {
  name?: string
  accountNumber?: string
  amount?: string
}

const MIN_AMOUNT = 1
const MAX_AMOUNT = 50000000

function validate(
  name: string,
  accountNumber: string,
  amount: string,
): FieldErrors {
  const errors: FieldErrors = {}

  if (!name.trim()) {
    errors.name = 'Full name is required.'
  } else if (name.trim().length > 100) {
    errors.name = 'Full name must not exceed 100 characters.'
  }

  if (!accountNumber.trim()) {
    errors.accountNumber = 'Account number is required.'
  } else if (!/^\d{6,50}$/.test(accountNumber.trim())) {
    errors.accountNumber = 'Account number must contain between 6 and 50 digits.'
  }

  if (amount.trim() === '') {
    errors.amount = 'Amount is required.'
  } else if (!/^\d+$/.test(amount.trim())) {
    errors.amount = 'Amount must be a whole number (KHR).'
  } else {
    const value = Number(amount)
    if (value < MIN_AMOUNT) {
      errors.amount = `Amount must be at least ${MIN_AMOUNT} KHR.`
    } else if (value > MAX_AMOUNT) {
      errors.amount = `Amount must not exceed ${MAX_AMOUNT.toLocaleString()} KHR.`
    }
  }

  return errors
}

export default function PaymentPage() {
  const [name, setName] = useState('')
  const [accountNumber, setAccountNumber] = useState('')
  const [amount, setAmount] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [serverError, setServerError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setServerError('')

    const validationErrors = validate(name, accountNumber, amount)
    setErrors(validationErrors)
    if (Object.keys(validationErrors).length > 0) {
      return
    }

    setSubmitting(true)

    try {
      const payment = await createPayment({
        name: name.trim(),
        account_number: accountNumber.trim(),
        amount: Number(amount),
      })

      submitToPayWay(payment.checkout.action, payment.checkout.fields)
    } catch (error) {
      setServerError(error instanceof Error ? error.message : 'Payment could not be completed. Please try again later.')
      setSubmitting(false)
    }
  }

  return (
    <main className="page">
      <div className="card">
        <div className="brand">
          <div className="logo">ABA</div>
          <h1>ABA PayWay Payment</h1>
          <p>Secure payment by ABA Bank</p>
        </div>

        {serverError && <div className="alert error">{serverError}</div>}

        <form noValidate onSubmit={handleSubmit}>
          <div className={`field ${errors.name ? 'invalid' : ''}`}>
            <label htmlFor="name">Full Name</label>
            <input
              id="name"
              type="text"
              autoComplete="name"
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
            {errors.name && <div className="error">{errors.name}</div>}
          </div>

          <div className={`field ${errors.accountNumber ? 'invalid' : ''}`}>
            <label htmlFor="accountNumber">Account Number</label>
            <input
              id="accountNumber"
              type="text"
              inputMode="numeric"
              autoComplete="off"
              value={accountNumber}
              onChange={(e) => setAccountNumber(e.target.value)}
            />
            {errors.accountNumber && <div className="error">{errors.accountNumber}</div>}
          </div>

          <div className={`field ${errors.amount ? 'invalid' : ''}`}>
            <label htmlFor="amount">Amount (KHR)</label>
            <input
              id="amount"
              type="text"
              inputMode="numeric"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
            />
            {errors.amount && <div className="error">{errors.amount}</div>}
          </div>

          <button className="primary" type="submit" disabled={submitting}>
            {submitting && <span className="spinner" />}
            {submitting ? 'Redirecting to PayWay…' : 'Continue to Payment'}
          </button>
        </form>
      </div>
    </main>
  )
}