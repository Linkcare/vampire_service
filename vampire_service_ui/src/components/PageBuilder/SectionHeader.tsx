import { Box, Flex } from "@chakra-ui/react";

/*
 Section title to separate different sections of the page.
*/
type SectionHeaderProps = {
  children: React.ReactNode;
  tools?: React.ReactNode; // Optional tools to be displayed on the right side of the title
};

function SectionHeader(props: SectionHeaderProps) {
  const { children, tools } = props;
  return (
    <Box
      style={{
        backgroundColor: "#81c4f9",
        padding: "10px",
        margin: "0 0 10px 0",
        borderRadius: "8px",
        fontWeight: "bold",
      }}
    >
      <Flex justify="space-between">
        <h1>{children}</h1>
        {tools && <Box>{tools}</Box>}
      </Flex>
    </Box>
  );
}

export default SectionHeader;
