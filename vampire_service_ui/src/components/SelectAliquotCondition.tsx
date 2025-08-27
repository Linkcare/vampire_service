/*
 <Select> component with a list of teams that can be selected for a lab shipment.
 The lab that corresponds to the current session's team ID is excluded from the list.
*/
import { NativeSelect } from "@chakra-ui/react";
import React from "react";
import { AliquotConditions } from "../types/ShipmentTypes";

interface SelectAliquotConditionProps {
  id: string;
  name: string | React.ReactNode;
  color?: string;
}
function SelectAliquotCondition({
  value,
  placeholder = "",
  bg = "",
  initialOption,
  handleChange,
}: {
  value?: string | null;
  placeholder?: string;
  bg?: string;
  initialOption?: string | null;
  handleChange?: (value: any) => void;
  excludeLocation?: string;
}) {
  const packageStatus: SelectAliquotConditionProps[] = [
    {
      id: AliquotConditions.NORMAL,
      name: "Normal",
      color: "green",
    },
    { id: AliquotConditions.BROKEN, name: "Broken", color: "red" },
    { id: AliquotConditions.DEFROST, name: "Defrosted", color: "red" },
    { id: AliquotConditions.MISSING, name: "Missing", color: "red" },
  ];

  let textColor = undefined;
  packageStatus.forEach((st) => {
    if (st.id === value) {
      textColor = st.color || "";
    }
  });

  return (
    <NativeSelect.Root size="sm" width="240px" variant="subtle">
      <NativeSelect.Field
        bg={bg}
        defaultValue={!value && initialOption ? initialOption : undefined}
        color={textColor}
        placeholder={placeholder}
        onChange={(e) => {
          if (handleChange) {
            handleChange(e.target.value);
          }
        }}
      >
        {packageStatus.map((st) => (
          <option key={st.id} value={st.id} color={st.color}>
            {st.name}
          </option>
        ))}
      </NativeSelect.Field>
      <NativeSelect.Indicator />
    </NativeSelect.Root>
  );
}

export default SelectAliquotCondition;
