#include <stdio.h>
#include <stdlib.h>

int main (int argc, const char * argv[])
{
	chdir("/Volumes/Data/Users/dhoerl/RedCats/Software/PHP_TESTER");
	
	for(int i=5; i<=6; ++i) {
		char foo[64], name[12];
		sprintf(name, "test%d.txt", i);
		sprintf(foo, "PropertyMaster.php < %s", name);
		
		printf("========= Running Test %d =========\n", i);
		
		system("pwd");
		system(foo);
	}
//	system("../../Parser.php < ../../test4.txt");
    return 0;
}
