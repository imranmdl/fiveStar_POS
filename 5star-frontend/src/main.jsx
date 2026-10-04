import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import './index.css'
import './styles/page.css'
import App from './App.jsx'
import InstallPrompt from './components/InstallPrompt.jsx'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter>
      <App />
      <InstallPrompt />
    </BrowserRouter>
  </StrictMode>,
)
