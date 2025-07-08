/*
 Section title to separate different sections of the page.
*/

import { Box } from "@chakra-ui/react";
import SectionHeader from "./SectionHeader";

type SectionBlockProps = {
  children?: React.ReactNode;
  title?: string;
  headerTools?: React.ReactNode;
};

function SectionBlock(props: SectionBlockProps) {
  const { children, title, headerTools } = props;
  return (
    <Box>
      {title && <SectionHeader tools={headerTools}>{title}</SectionHeader>}
      <Box
        style={{
          padding: " 0 15px",
        }}
      >
        {children}
      </Box>
    </Box>
  );
}

export default SectionBlock;
