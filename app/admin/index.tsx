import '../styles/tokens.css';
import '../styles/tailwind.css';
import '../styles/chrome.css';
import { mount } from '@shared/mount';
import { AdminApp } from './AdminApp';

mount( 'djinn-admin-root', <AdminApp /> );
