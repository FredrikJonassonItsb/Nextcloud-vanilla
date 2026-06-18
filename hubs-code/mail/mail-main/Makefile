_FROM_APP := 1
ifndef PLATFORM_ROOT
PLATFORM_ROOT := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))/../..
endif
APP_ROOT := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))
include $(PLATFORM_ROOT)/Makefile
