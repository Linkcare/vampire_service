import { invokeApi } from "./LinkcareApi";

interface PictureRef {
  ref?: string | null;
  url?: string | null;
}
interface PictureList {
  insignia?: PictureRef | null;
  editable: boolean;
}

interface Identifier {
  label: string;
  value: string;
  auto_generated?: boolean;
  validation_id?: string | null;
  description?: string | null;
}

interface ContactName {
  given_name?: string | null;
  family_name?: string | null;
  complete_name?: string | null;
  editable?: boolean;
  source?: string | null;
}

interface ApiContact {
  ref: string;
  username?: string | null;
  editable: boolean;
  pictures?: PictureList | null;
  identifiers?: Identifier[] | null;
  name?: ContactName | null;
}

/* ****************************************
 * user_get_contact()
 * ****************************************/
export const user_get_contact = async (user: number): Promise<ApiContact> => {
  interface GetContactParams {
    user: number;
    team: string | null;
    mode: string | null;
  }

  const fnParams: GetContactParams = {
    user: user,
    team: null,
    mode: null,
  };

  try {
    const contact = await invokeApi<ApiContact>("user_get_contact", fnParams);
    return contact;
  } catch (error) {
    throw error;
  }
};
