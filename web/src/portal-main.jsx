import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import '@fontsource-variable/inter'
import './index.css'
import { setTokenKey } from './api'
import { ToastProvider } from './ui'
import PortalApp from './PortalApp.jsx'
setTokenKey('wab_client_token')
import { installErrorReporter } from './errorlogs'
installErrorReporter('/api', 'portal')
createRoot(document.getElementById('root')).render(<StrictMode><ToastProvider><PortalApp /></ToastProvider></StrictMode>)
