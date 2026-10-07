import { Route, Routes } from 'react-router-dom'
import PaymentPage from './pages/PaymentPage'
import SuccessPage from './pages/SuccessPage'
import FailedPage from './pages/FailedPage'
import CancelledPage from './pages/CancelledPage'
import PendingPage from './pages/PendingPage'

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<PaymentPage />} />
      <Route path="/payment/success" element={<SuccessPage />} />
      <Route path="/payment/failed" element={<FailedPage />} />
      <Route path="/payment/cancelled" element={<CancelledPage />} />
      <Route path="/payment/pending" element={<PendingPage />} />
    </Routes>
  )
}