import axios from 'axios';
import { route as ziggyRoute } from 'ziggy-js';
import { Ziggy } from './ziggy';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.route = (name, params, absolute = false, config = Ziggy) => ziggyRoute(name, params, absolute, {
    ...config,
    location: window.location,
});
