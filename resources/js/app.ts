/* eslint-disable import/no-unresolved */

// eslint-disable-next-line camelcase
// import { livewire_hot_reload } from 'virtual:livewire-hot-reload';

// eslint-disable-next-line @typescript-eslint/ban-ts-comment -- This actually works in a TALL stack app with Livewire 3.
// @ts-ignore
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import {
  linkifyDirective,
  modalComponent,
  newsCarouselComponent,
  toggleAchievementRowsComponent,
  tooltipComponent,
} from './alpine';
import {
  autoExpandTextInput,
  copyToClipboard,
  deleteCookie,
  fetcher,
  getCookie,
  getStringByteCount,
  handleLeaderboardTabClick,
  initializeTextareaCounter,
  injectShortcode,
  loadPostPreview,
  setCookie,
  themeChange,
  toggleUserCompletedSetsVisibility,
  updateUrlParameter,
} from './utils';
import { lazyLoadModuleOnIdFound } from './lazyLoadModuleOnIdFound';

// livewire_hot_reload();

lazyLoadModuleOnIdFound({
  elementId: 'reorder-site-awards-header',
  codeFileName: 'reorderSiteAwards',
  moduleNameToAttachToWindow: 'reorderSiteAwards',
});

// Global Utils
window.autoExpandTextInput = autoExpandTextInput;
window.copyToClipboard = copyToClipboard;
window.deleteCookie = deleteCookie;
window.fetcher = fetcher;
window.getCookie = getCookie;
window.getStringByteCount = getStringByteCount;
window.handleLeaderboardTabClick = handleLeaderboardTabClick;
window.initializeTextareaCounter = initializeTextareaCounter;
window.injectShortcode = injectShortcode;
window.loadPostPreview = loadPostPreview;
window.setCookie = setCookie;
window.toggleUserCompletedSetsVisibility = toggleUserCompletedSetsVisibility;
window.updateUrlParameter = updateUrlParameter;

// Alpine.js Components
window.modalComponent = modalComponent;
window.newsCarouselComponent = newsCarouselComponent;
window.toggleAchievementRowsComponent = toggleAchievementRowsComponent;
window.tooltipComponent = tooltipComponent;

// Alpine.js Directives
Alpine.directive('linkify', linkifyDirective);

Livewire.start();

themeChange();
