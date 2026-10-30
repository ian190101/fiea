import '../css/app.css';
import { applyTheme } from './Utils/theme';
import { readPreference } from './Utils/preferences';
import { showError } from './Services/ErrorService';

applyTheme(readPreference('theme_preference', 'light'));
const diagnostic = document.getElementById('error-diagnostic');
if (diagnostic) showError(JSON.parse(diagnostic.textContent));
