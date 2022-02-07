import { JsonDecoder } from 'ts.data.json';

import { ThemeMode, User } from '@centreon/ui-context';

export const userDecoder = JsonDecoder.object<User>(
  {
    alias: JsonDecoder.string,
    default_page: JsonDecoder.optional(
      JsonDecoder.nullable(JsonDecoder.string),
    ),
    isExportButtonEnabled: JsonDecoder.boolean,
    locale: JsonDecoder.string,
    name: JsonDecoder.string,
    themeMode: JsonDecoder.optional(
      JsonDecoder.enumeration<ThemeMode>(ThemeMode, 'ThemeMode'),
    ),
    timezone: JsonDecoder.string,
    use_deprecated_pages: JsonDecoder.boolean,
  },
  'User parameters',
  {
    isExportButtonEnabled: 'is_export_button_enabled',
  },
);
