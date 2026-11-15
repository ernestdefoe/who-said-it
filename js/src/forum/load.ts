/** The quiz page: its chunk, then core's lazy page parts it draws with. */
export const loadPage = () => import('./components/WhoSaidItPage').then(async (page) => (await page.loadCore(), page));
