import axios from 'axios';

// Configure Axios defaults for Laravel Sanctum/session authentication
axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;

// Set the XSRF cookie name that Laravel uses
axios.defaults.xsrfCookieName = 'XSRF-TOKEN';
axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';

// Set default headers
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['Accept'] = 'application/json';

export default axios;
