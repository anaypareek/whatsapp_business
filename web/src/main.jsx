import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import '@fontsource-variable/inter'
import './index.css'
import App from './App.jsx'
import { ToastProvider } from './ui'
createRoot(document.getElementById('root')).render(<StrictMode><ToastProvider><App /></ToastProvider></StrictMode>)
