import { createPanelApp } from './ui/index.js';
import site, { routes } from './site.js';

createPanelApp({
  site,
  routes,
  loadPage: (name) => import(`./pages/${name}.js`)
});
