import { useAtomValue } from 'jotai/utils';
import { equals } from 'ramda';
import { useTranslation } from 'react-i18next';
import clsx from 'clsx';

import { makeStyles } from '@mui/styles';

import { useMemoComponent } from '@centreon/ui';
import { ThemeMode, userAtom } from '@centreon/ui-context';

import logoCentreon from '../assets/logo-centreon-colors.png';
import logoWhite from '../assets/centreon-logo-white.svg';

import { labelCentreonLogo } from './translatedLabels';

const useStyles = makeStyles({
  centreonLogo: {
    height: 'auto',
    width: 'auto',
  },
  centreonLogoWhite: {
    height: 57,
    width: 250,
  },
});

const Logo = (): JSX.Element => {
  const classes = useStyles();
  const { t } = useTranslation();
  const { themeMode } = useAtomValue(userAtom);
  const logo = equals(themeMode, ThemeMode.light) ? logoCentreon : logoWhite;
  const isDarkMode = equals(themeMode, ThemeMode.dark);

  return useMemoComponent({
    Component: (
      <img
        alt={t(labelCentreonLogo)}
        aria-label={t(labelCentreonLogo)}
        className={clsx(classes.centreonLogo, {
          [classes.centreonLogoWhite]: isDarkMode,
        })}
        src={logo}
      />
    ),
    memoProps: [isDarkMode],
  });
};

export default Logo;
