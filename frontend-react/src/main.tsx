import { createRoot } from 'react-dom/client';
import App from './App';
import './styles/index.css';

// StrictMode убран - вызывал двойные запросы к API
createRoot(document.getElementById('root')!).render(<App />);
