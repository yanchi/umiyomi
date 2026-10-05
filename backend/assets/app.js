/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';
import { initAnalytics } from './analytics/analytics.js';
import { initAnalyticsOptOut } from './analytics/analytics-optout-ui.js';
import { initCoordinateInput } from './coordinate-input/coordinate-input-ui.js';
import { initFavorites } from './favorites/favorites-ui.js';

// ES Module は defer 相当で実行されるため、DOMContentLoaded を待たなくても本文は読み込み済み
// 計測は他の初期化より先にする。後続の操作のイベントが config の後に積まれるようにするため
initAnalytics(document);
initAnalyticsOptOut(document);
initFavorites(document);
initCoordinateInput(document);
