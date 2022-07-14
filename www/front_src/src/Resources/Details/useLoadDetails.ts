import { useEffect } from 'react';

import { isNil, ifElse, pathEq, always, pathOr } from 'ramda';
import { useTranslation } from 'react-i18next';
import { useAtomValue, useUpdateAtom } from 'jotai/utils';
import { useAtom } from 'jotai';

import { useRequest, getData } from '@centreon/ui';

import { replaceBasename } from '../helpers';
import {
  labelNoResourceFound,
  labelSomethingWentWrong,
} from '../translatedLabels';
import useTimePeriod from '../Graph/Performance/TimePeriods/useTimePeriod';
import {
  customTimePeriodAtom,
  getNewCustomTimePeriod,
  resourceDetailsUpdatedAtom,
  selectedTimePeriodAtom,
} from '../Graph/Performance/TimePeriods/timePeriodAtoms';

import { ResourceDetails } from './models';
import {
  clearSelectedResourceDerivedAtom,
  detailsAtom,
  selectedResourceUuidAtom,
  selectedResourceDetailsEndpointAtom,
} from './detailsAtoms';
import { ChangeCustomTimePeriodProps } from './tabs/Graph/models';

export interface DetailsState {
  changeCustomTimePeriod: (props: ChangeCustomTimePeriodProps) => void;
  loadDetails: () => void;
}

const useLoadDetails = (): DetailsState => {
  const { t } = useTranslation();

  const { sendRequest, sending } = useRequest<ResourceDetails>({
    getErrorMessage: ifElse(
      pathEq(['response', 'status'], 404),
      always(t(labelNoResourceFound)),
      pathOr(t(labelSomethingWentWrong), ['response', 'data', 'message']),
    ),
    request: getData,
  });

  const [customTimePeriod, setCustomTimePeriod] = useAtom(customTimePeriodAtom);
  const selectedResourceUuid = useAtomValue(selectedResourceUuidAtom);
  const selectedResourceDetailsEndpoint = useAtomValue(
    selectedResourceDetailsEndpointAtom,
  );
  const setDetails = useUpdateAtom(detailsAtom);
  const clearSelectedResource = useUpdateAtom(clearSelectedResourceDerivedAtom);
  const setSelectedTimePeriod = useUpdateAtom(selectedTimePeriodAtom);
  const setResourceDetailsUpdated = useUpdateAtom(resourceDetailsUpdatedAtom);
  const resourceDetailsEndPoint = replaceBasename({
    endpoint: selectedResourceDetailsEndpoint || '',
    newWord: './',
  });
  useTimePeriod({
    sending,
  });

  const loadDetails = (): void => {
    if (isNil(selectedResourceDetailsEndpoint)) {
      return;
    }

    sendRequest({
      endpoint: resourceDetailsEndPoint,
    })
      .then(setDetails)
      .catch(() => {
        clearSelectedResource();
      });
  };

  const changeCustomTimePeriod = ({ date, property }): void => {
    const newCustomTimePeriod = getNewCustomTimePeriod({
      ...customTimePeriod,
      [property]: date,
    });

    setCustomTimePeriod(newCustomTimePeriod);
    setSelectedTimePeriod(null);
    setResourceDetailsUpdated(false);
  };

  useEffect(() => {
    setDetails(undefined);
    loadDetails();
  }, [selectedResourceUuid]);

  return {
    changeCustomTimePeriod,
    loadDetails,
  };
};

export default useLoadDetails;
