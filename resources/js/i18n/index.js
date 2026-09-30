import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

import id from './locales/id.json';
import en from './locales/en.json';

const resources = {
    id: { translation: id },
    en: { translation: en },
};

i18n
    .use(initReactI18next)
    .init({
        resources,
        lng: 'id',
        fallbackLng: 'id',
        supportedLngs: ['id'],
        interpolation: {
            escapeValue: false,
        },
    });

export default i18n;

export const languages = [{ code: 'id', name: 'Indonesia', nativeName: 'Indonesia' }];
